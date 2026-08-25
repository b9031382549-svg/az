<?php

namespace App\Console\Commands;

use App\Services\Classify\CatalogRetriever;
use Illuminate\Console\Command;

/**
 * FREE calibration for the planned root chapter-shortlist. broker:fork-eval showed
 * ~2/3 of the broker's errors are a wrong pick at the 97-way root chapter fork. The
 * fix is to hand the broker a retrieval shortlist of candidate chapters at the root
 * instead of all 97 — but that only works if the TRUE chapter is in the shortlist.
 *
 * This measures exactly that ceiling: for each {name,gold} item, retrieve candidates
 * (Ollama embeddings + pgvector + lexical — NO paid LLM calls), rank the DISTINCT
 * 2-digit chapters by first occurrence, and report recall@N = share of items whose
 * gold chapter is within the top-N chapters. Picks N and proves the ceiling before
 * any control-flow change. Uses the RAW item name (no brief), so it is a conservative
 * lower bound — the live prior, running on the brief query, can only do better.
 */
class BrokerChapterRecall extends Command
{
    protected $signature = 'broker:chapter-recall
        {--file=research-data/finetune/gold-split/test.jsonl : {name,gold 4-digit} JSONL}
        {--k=60 : candidate pool size to derive the chapter ranking from}
        {--limit=0 : cap items (0 = all; this is FREE, no paid LLM)}
        {--offset=0 : skip the first N items}';

    protected $description = 'FREE: retrieval chapter recall@N over the gold set — the ceiling for a root chapter-shortlist prior.';

    public function handle(): int
    {
        $path = base_path((string) $this->option('file'));
        if (! is_file($path)) {
            $this->error("Missing: {$path}");

            return self::FAILURE;
        }

        $all = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if (isset($r['name'], $r['gold'])) {
                $all[] = $r;
            }
        }
        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');
        $items = array_slice($all, $offset, $limit > 0 ? $limit : null);
        $k = (int) $this->option('k');

        /** @var CatalogRetriever $retriever */
        $retriever = app(CatalogRetriever::class);

        $maxN = 12;
        $hitsAtN = array_fill(1, $maxN, 0); // gold chapter within top-N chapters
        $present = 0;                        // gold chapter anywhere in the pool
        $total = count($items);
        $i = 0;

        foreach ($items as $item) {
            $goldChap = substr((string) $item['gold'], 0, 2);

            $cands = $retriever->candidates([(string) $item['name']], $k);
            $chapters = [];
            $seen = [];
            foreach ($cands as $c) {
                $ch = substr((string) $c->code, 0, 2);
                if ($ch === '' || isset($seen[$ch])) {
                    continue;
                }
                $seen[$ch] = true;
                $chapters[] = $ch;
            }

            $rank = array_search($goldChap, $chapters, true); // 0-based position, or false
            if ($rank !== false) {
                $present++;
                for ($n = $rank + 1; $n <= $maxN; $n++) {
                    $hitsAtN[$n]++;
                }
            }

            if (++$i % 100 === 0) {
                $this->output->write(sprintf("\r  %d/%d  recall@5=%.1f%%   ", $i, $total, 100 * $hitsAtN[5] / $i));
            }
        }
        $this->newLine(2);

        $rows = [];
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 10, 12] as $n) {
            $rows[] = ["recall@{$n}", sprintf('%.1f%%', $total ? 100 * $hitsAtN[$n] / $total : 0)];
        }
        $this->info("Chapter recall@N  (gold chapter within the top-N retrieved chapters, pool k={$k})");
        $this->table(['N chapters', 'recall'], $rows);
        $this->line(sprintf('gold chapter present anywhere in pool: %.1f%%  (this is the hard ceiling)',
            $total ? 100 * $present / $total : 0));

        return self::SUCCESS;
    }
}
