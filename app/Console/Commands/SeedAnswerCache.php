<?php

namespace App\Console\Commands;

use App\Models\AnswerCache;
use App\Models\GoldLabel;
use Illuminate\Console\Command;

/**
 * Seed the answer cache from a reference source (default: Fedor) already imported
 * into gold_labels. One verified answer per name (4-digit heading, or a service).
 * Idempotent (upsert by name_key). No LLM.
 */
class SeedAnswerCache extends Command
{
    protected $signature = 'cache:seed
        {--source=fedor : gold source to seed from}
        {--exclude-benchmarks : never seed a name that appears in any held-out benchmark/eval file}
        {--fresh : truncate the cache first}';

    protected $description = 'Seed the answer cache from the reference (Fedor) gold labels';

    public function handle(): int
    {
        // The 'gold' source alone hydrates ~106k Eloquent models — the default 512M can
        // OOM (silently, with no error, well before the final summary line prints).
        ini_set('memory_limit', '1024M');

        if ($this->option('fresh')) {
            // Only the PRODUCTION scope (0) — never wipe datasets' bound memory.
            AnswerCache::where('test_dataset_id', 0)->delete();
            $this->warn('Cleared the production answer_cache (test_dataset_id = 0).');
        }

        $heldOut = $this->option('exclude-benchmarks') ? $this->heldOutNameKeys() : [];
        if ($heldOut !== []) {
            $this->info('Held-out (never seeded): '.count($heldOut).' distinct names across '
                .count($this->heldOutFiles()).' benchmark/eval files.');
        }

        $source = (string) $this->option('source');

        // Never let THIS source's seed silently overwrite an existing row that already
        // belongs to a DIFFERENT source (e.g. a broad later 'gold' import must not clobber
        // an earlier, more curated 'fedor' answer just because the same name happens to be
        // in both references) — upsert() alone can't express that, it updates 'source' too.
        $otherSourceKeys = AnswerCache::where('test_dataset_id', 0)
            ->where('source', '!=', $source)
            ->pluck('name_key')
            ->flip();

        $skipped = 0;
        $skippedOtherSource = 0;
        $rows = GoldLabel::where('source', $source)->get()
            ->filter(function ($g) use ($heldOut, $otherSourceKeys, &$skipped, &$skippedOtherSource) {
                if (isset($heldOut[$g->name_key])) {
                    $skipped++;

                    return false;
                }
                if ($otherSourceKeys->has($g->name_key)) {
                    $skippedOtherSource++;

                    return false;
                }

                return true;
            })
            ->map(fn ($g) => [
                'test_dataset_id' => 0, // production scope
                'source' => $g->source,
                'name' => $g->name,
                'name_key' => $g->name_key,
                'heading' => $g->is_service ? null : $g->heading,
                'is_service' => (bool) $g->is_service,
                'tier' => $g->tier,
                'meta' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->keyBy('name_key') // one row per name_key for the upsert
            ->values()
            ->all();

        if ($rows === [] && $skipped === 0 && $skippedOtherSource === 0) {
            $this->error("No gold labels for source '{$source}'. Run benchmark:import-gold first.");

            return self::FAILURE;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            AnswerCache::upsert($chunk, ['test_dataset_id', 'name_key'], ['source', 'name', 'heading', 'is_service', 'tier', 'updated_at']);
        }

        $goods = AnswerCache::where('test_dataset_id', 0)->whereNotNull('heading')->count();
        $services = AnswerCache::where('test_dataset_id', 0)->where('is_service', true)->count();
        $this->info('Seeded answer_cache: '.count($rows)." entries from '{$source}' ({$goods} goods, {$services} services)."
            .($skipped > 0 ? " Skipped {$skipped} held-out names." : '')
            .($skippedOtherSource > 0 ? " Skipped {$skippedOtherSource} names already owned by another source." : ''));

        return self::SUCCESS;
    }

    /**
     * Held-out benchmark/eval file paths (repo-relative, from config so tests can point
     * at fixtures instead of the real research files). A name in ANY of these must NEVER
     * be seeded into live-answering memory, or every future accuracy measurement against
     * it would be measuring the classifier's ability to recall its own cache, not real
     * classification (the same 0-leak discipline gold-v1/build.py already applies when
     * building the fine-tune corpus).
     *
     * @return array<int, string>
     */
    private function heldOutFiles(): array
    {
        return (array) config('classify.held_out_benchmarks', []);
    }

    /**
     * The union of every name (normalized) that appears in any held-out benchmark/eval
     * file — a set, so lookups are O(1) against ~2.5k names regardless of gold's size.
     * A missing file is a loud warning, not a silent partial exclusion — better to fail
     * visibly than seed something we should not.
     *
     * @return array<string, true>
     */
    private function heldOutNameKeys(): array
    {
        $keys = [];

        foreach ($this->heldOutFiles() as $rel) {
            // Repo-relative in prod config ("123/benchmark_...csv"), but an already-
            // absolute path (e.g. a test fixture under storage_path()) must NOT also get
            // base_path() prepended, or it resolves to a doubled, nonexistent path.
            $path = str_starts_with($rel, '/') ? $rel : base_path($rel);
            if (! is_file($path)) {
                $this->warn("  held-out file not found (skipped, NOT excluded): {$path}");

                continue;
            }

            if (str_ends_with($rel, '.jsonl')) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $name = trim((string) (json_decode($line, true)['name'] ?? ''));
                    if ($name !== '') {
                        $keys[GoldLabel::keyFor($name)] = true;
                    }
                }

                continue;
            }

            $fh = fopen($path, 'r');
            $header = fgetcsv($fh);
            $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);
            $nameCol = array_search('name', array_map(fn ($h) => mb_strtolower(trim((string) $h)), $header), true);
            if ($nameCol !== false) {
                while (($r = fgetcsv($fh)) !== false) {
                    $name = trim((string) ($r[$nameCol] ?? ''));
                    if ($name !== '') {
                        $keys[GoldLabel::keyFor($name)] = true;
                    }
                }
            }
            fclose($fh);
        }

        return $keys;
    }
}
