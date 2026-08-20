<?php

namespace App\Console\Commands;

use App\Services\Embeddings\OllamaEmbedder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pure-vector retrieval eval — deliberately NOT the classify pipeline.
 *
 * For each held-out item it embeds the raw name, runs a single KNN against the
 * catalog embedding column, collapses candidate codes to their 4-digit HS
 * heading, and records the rank at which the gold heading first appears. No
 * answer_cache, no mechanisms, no consensus, no LLM — so nothing but the
 * embedder + vector geometry is being measured. Baseline (stock bge-m3, --table
 * catalog) and a later fine-tuned run (--table catalog_ft) use the SAME command
 * so the two result files are directly comparable.
 */
class ClassifyVectorBaseline extends Command
{
    protected $signature = 'classify:vector-baseline
        {--file=research-data/finetune/gold-split/test.jsonl : JSONL, one {"name":..,"gold":"NNNN"} per line}
        {--table=catalog : Table with (code, embedding) to search — catalog or catalog_ft}
        {--k=50 : Candidate codes fetched per query (max recall cutoff reported)}
        {--limit=0 : Cap number of items (0 = all)}
        {--query-vectors= : JSONL of precomputed query vectors (one array per line, aligned to --file). When set, Ollama is NOT called — used for the fine-tuned run so queries are embedded by the FT model, not stock bge-m3}
        {--out= : Output JSONL path (default storage/app/vector-baseline-<table>.jsonl)}';

    protected $description = 'Pure-vector retrieval eval: query → KNN → 4-digit heading rank. No pipeline, cache or memory.';

    /** Recall cutoffs (in candidate CODES) reported in the summary. */
    private const CUTOFFS = [1, 3, 5, 10, 20, 30, 40, 50];

    public function handle(OllamaEmbedder $embedder): int
    {
        $path = base_path((string) $this->option('file'));
        if (! is_file($path)) {
            $this->error("Test file not found: {$path}");

            return self::FAILURE;
        }

        $table = preg_replace('/[^a-z0-9_]/i', '', (string) $this->option('table')); // guard: identifier only
        $k = max(1, (int) $this->option('k'));
        $limit = (int) $this->option('limit');
        $out = (string) ($this->option('out') ?: storage_path("app/vector-baseline-{$table}.jsonl"));

        // Load items.
        $items = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && isset($row['name'], $row['gold'])) {
                $items[] = ['name' => (string) $row['name'], 'gold' => (string) $row['gold']];
            }
            if ($limit > 0 && count($items) >= $limit) {
                break;
            }
        }
        $this->info(count($items).' items loaded from '.$this->option('file'));

        // Precomputed query vectors (fine-tuned run): read them aligned to $items
        // instead of embedding via Ollama. Keeps the FT comparison reproducible and
        // server-free — the GPU box emits one vector array per line, in file order.
        $queryVectors = null;
        if ($qvPath = (string) $this->option('query-vectors')) {
            $qvPath = base_path($qvPath);
            $queryVectors = array_map(
                fn ($l) => json_decode($l, true),
                file($qvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            );
            if (count($queryVectors) !== count($items)) {
                $this->error('query-vectors count ('.count($queryVectors).') != items ('.count($items).')');

                return self::FAILURE;
            }
            $this->info('Using precomputed query vectors (Ollama not called).');
        }

        // Which gold headings even exist in the catalog — an absent one is
        // structurally unreachable (0 recall by construction, not a model miss).
        $catalogHeadings = DB::table($table)
            ->selectRaw('DISTINCT left(code,4) AS h')
            ->pluck('h')
            ->flip();

        $fh = fopen($out, 'w');
        $stats = array_fill_keys(self::CUTOFFS, 0);
        $rank1 = 0;
        $headingRank1 = 0;
        $mrrSum = 0.0;
        $goldAbsent = 0;
        $done = 0;

        foreach (array_chunk($items, 64, true) as $chunk) {
            $offsets = array_keys($chunk);
            $vectors = $queryVectors === null
                ? $embedder->embed(array_map(fn ($i) => $i['name'], $chunk))
                : array_map(fn ($o) => $queryVectors[$o], $offsets);
            $vectors = array_values($vectors);

            foreach (array_values($chunk) as $j => $item) {
                $vec = OllamaEmbedder::toSqlVector($vectors[$j]);

                /** @var Collection $rows ranked nearest-first, with cosine sim */
                $rows = DB::table($table)
                    ->selectRaw('code, 1 - (embedding <=> ?::vector) AS sim', [$vec])
                    ->whereNotNull('embedding')
                    ->orderByRaw('embedding <=> ?::vector', [$vec])
                    ->limit($k)
                    ->get();
                $codes = $rows->pluck('code')->all();
                $top1Sim = $rows->isNotEmpty() ? round((float) $rows[0]->sim, 4) : null;

                $gold = $item['gold'];
                $top1Correct = $rows->isNotEmpty() && substr((string) $rows[0]->code, 0, 4) === $gold;
                $goldInCatalog = $catalogHeadings->has($gold);
                if (! $goldInCatalog) {
                    $goldAbsent++;
                }

                // Rank (1-based) of the first candidate CODE whose heading == gold.
                $codeRank = null;
                foreach ($codes as $pos => $code) {
                    if (substr($code, 0, 4) === $gold) {
                        $codeRank = $pos + 1;
                        break;
                    }
                }

                // Rank of the gold among DISTINCT headings, in nearest-first order.
                $seen = [];
                foreach ($codes as $code) {
                    $h = substr($code, 0, 4);
                    if (! in_array($h, $seen, true)) {
                        $seen[] = $h;
                    }
                }
                $headingRank = ($p = array_search($gold, $seen, true)) === false ? null : $p + 1;

                if ($codeRank !== null) {
                    foreach (self::CUTOFFS as $c) {
                        if ($codeRank <= $c) {
                            $stats[$c]++;
                        }
                    }
                    $mrrSum += 1.0 / $codeRank;
                    if ($codeRank === 1) {
                        $rank1++;
                    }
                }
                if ($headingRank === 1) {
                    $headingRank1++;
                }

                fwrite($fh, json_encode([
                    'name' => $item['name'],
                    'gold' => $gold,
                    'gold_in_catalog' => $goldInCatalog,
                    'code_rank' => $codeRank,
                    'heading_rank' => $headingRank,
                    'top_headings' => array_slice($seen, 0, 10),
                    'top1_sim' => $top1Sim,
                    'top1_correct' => $top1Correct,
                ], JSON_UNESCAPED_UNICODE)."\n");
            }

            $done += count($chunk);
            $this->output->write("\r  embedded+searched {$done}/".count($items).'   ');
        }
        fclose($fh);
        $this->newLine(2);

        $n = max(1, count($items));
        $reachable = max(1, $n - $goldAbsent);

        $this->info("Results → {$out}");
        $this->line("Items: {$n}   gold-heading absent from catalog: {$goldAbsent}   reachable: ".($n - $goldAbsent));
        $this->newLine();

        $rows = [];
        foreach (self::CUTOFFS as $c) {
            $rows[] = [
                "recall@{$c} (codes)",
                sprintf('%.1f%%', 100 * $stats[$c] / $n),
                sprintf('%.1f%%', 100 * $stats[$c] / $reachable),
            ];
        }
        $rows[] = ['rank-1 (top code = gold)', sprintf('%.1f%%', 100 * $rank1 / $n), sprintf('%.1f%%', 100 * $rank1 / $reachable)];
        $rows[] = ['heading rank-1', sprintf('%.1f%%', 100 * $headingRank1 / $n), sprintf('%.1f%%', 100 * $headingRank1 / $reachable)];
        $rows[] = ['MRR (code-level)', sprintf('%.3f', $mrrSum / $n), sprintf('%.3f', $mrrSum / $reachable)];

        $this->table(['metric', 'over all items', 'over reachable'], $rows);

        return self::SUCCESS;
    }
}
