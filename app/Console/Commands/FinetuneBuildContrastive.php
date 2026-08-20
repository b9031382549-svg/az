<?php

namespace App\Console\Commands;

use App\Support\CatalogLeaf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turn the generative SFT corpus (chat messages, one assistant JSON per item)
 * into CONTRASTIVE pairs for embedder fine-tuning: {anchor, positive, heading}.
 *
 *   anchor   = the noisy invoice item text (the "ITEM: …" user turn) — the query
 *              distribution we actually serve.
 *   positive = a canonical catalog passage under the gold 4-digit heading (the
 *              shortest — i.e. most generic — leaf name in that heading).
 *
 * MNRL / in-batch negatives is the intended loss, so only (anchor, positive)
 * pairs are needed — no explicit negative mining here (that can be added on the
 * GPU box, which is fast, if the plain in-batch run underperforms). Services and
 * items whose gold heading is absent from the catalog are dropped (unreachable).
 * Test names are excluded best-effort as a leakage backstop on top of the
 * already-disjoint gold-split.
 */
class FinetuneBuildContrastive extends Command
{
    protected $signature = 'finetune:build-contrastive
        {--train=research-data/finetune/gold-split/train.jsonl : chat-SFT JSONL}
        {--test=research-data/finetune/gold-split/test.jsonl : held-out to exclude}
        {--exclude-misc : v2 — pick the shortest NON-service leaf as the positive (skip bare "others/parts")}
        {--out=research-data/finetune/contrastive/train_pairs.jsonl : output pairs}';

    protected $description = 'Build {anchor,positive,heading} contrastive pairs from the SFT corpus (drops services, excludes test).';

    public function handle(): int
    {
        $trainPath = base_path((string) $this->option('train'));
        $testPath = base_path((string) $this->option('test'));
        $outPath = base_path((string) $this->option('out'));

        if (! is_file($trainPath)) {
            $this->error("Train file not found: {$trainPath}");

            return self::FAILURE;
        }

        // Heading → positive passage. v1: shortest leaf overall (could be a "– others"
        // bucket). v2 (--exclude-misc): shortest NON-service leaf, so the anchor is
        // pulled toward a real product wording, not a catch-all.
        $excludeMisc = (bool) $this->option('exclude-misc');
        $repsV1 = [];   // shortest overall (old behaviour) — kept for the before/after view
        $reps = [];     // chosen positive (v2 when --exclude-misc, else v1)
        DB::table('catalog')->select('code', 'name')->orderByRaw('length(name)')->orderBy('code')
            ->each(function ($row) use (&$reps, &$repsV1, $excludeMisc) {
                $h = substr($row->code, 0, 4);
                $repsV1[$h] ??= $row->name;
                if (! $excludeMisc) {
                    $reps[$h] ??= $row->name;

                    return;
                }
                if (! isset($reps[$h]) && ! CatalogLeaf::isMisc($row->name)) {
                    $reps[$h] = $row->name; // first (shortest) non-service leaf
                }
            });
        // Fallback: a heading whose every leaf is a service bucket keeps its v1 rep.
        foreach ($repsV1 as $h => $name) {
            $reps[$h] ??= $name;
        }
        $this->info(count($reps).' catalog headings mapped to a positive passage'.($excludeMisc ? ' (v2, service leaves skipped).' : '.'));

        if ($excludeMisc) {
            $changed = array_filter($reps, fn ($v, $h) => $v !== ($repsV1[$h] ?? null), ARRAY_FILTER_USE_BOTH);
            $this->line('Positive changed for '.count($changed).' headings. Examples (was → now):');
            foreach (array_slice($changed, 0, 6, true) as $h => $now) {
                $this->line("  [{$h}] was: ".$this->clip($repsV1[$h]).'  →  now: '.$this->clip($now));
            }
            $this->newLine();
        }

        // Best-effort leakage backstop: normalized test names.
        $exclude = [];
        if (is_file($testPath)) {
            foreach (file($testPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $row = json_decode($line, true);
                if (isset($row['name'])) {
                    $exclude[$this->norm((string) $row['name'])] = true;
                }
            }
        }

        @mkdir(dirname($outPath), 0775, true);
        $fh = fopen($outPath, 'w');

        $written = 0;
        $svc = 0;
        $unreachable = 0;
        $excluded = 0;
        $bad = 0;
        $in = fopen($trainPath, 'r');

        while (($line = fgets($in)) !== false) {
            $obj = json_decode($line, true);
            $messages = $obj['messages'] ?? null;
            if (! is_array($messages)) {
                $bad++;

                continue;
            }

            $anchor = null;
            $answer = null;
            foreach ($messages as $m) {
                if (($m['role'] ?? '') === 'user') {
                    $anchor = preg_replace('/^\s*ITEM:\s*/u', '', (string) ($m['content'] ?? ''));
                } elseif (($m['role'] ?? '') === 'assistant') {
                    $answer = json_decode((string) ($m['content'] ?? ''), true);
                }
            }

            $anchor = trim((string) $anchor);
            if ($anchor === '' || ! is_array($answer)) {
                $bad++;

                continue;
            }

            if (($answer['kind'] ?? '') === 'service') {
                $svc++;

                continue;
            }

            $heading = (string) ($answer['heading'] ?? '');
            if (! preg_match('/^\d{4}$/', $heading)) {
                $bad++;

                continue;
            }
            if (! isset($reps[$heading])) {
                $unreachable++;

                continue;
            }
            if (isset($exclude[$this->norm($anchor)])) {
                $excluded++;

                continue;
            }

            fwrite($fh, json_encode([
                'anchor' => $anchor,
                'positive' => $reps[$heading],
                'heading' => $heading,
            ], JSON_UNESCAPED_UNICODE)."\n");
            $written++;
        }
        fclose($in);
        fclose($fh);

        $this->newLine();
        $this->info("Wrote {$written} pairs → {$outPath}");
        $this->table(['dropped', 'count'], [
            ['service items', $svc],
            ['gold heading absent from catalog', $unreachable],
            ['test-leak excluded', $excluded],
            ['unparseable / no 4-digit', $bad],
        ]);

        return self::SUCCESS;
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return (string) preg_replace('/\s+/u', ' ', $s);
    }

    /** Compact a long hierarchical name to head + leaf for readable examples. */
    private function clip(string $name): string
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/–/u', $name) ?: [$name])));
        if (count($parts) <= 1) {
            return mb_substr($name, 0, 60);
        }

        return mb_substr($parts[0], 0, 24).' … '.mb_substr(end($parts), 0, 34);
    }
}
