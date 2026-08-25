<?php

namespace App\Console\Commands;

use App\Services\Classify\Mechanisms\BrokerDescentMechanism;
use Illuminate\Console\Command;

/**
 * Fail-fast gate for the chapter-shortlist idea: does the broker's OWN proposed
 * shortlist (model reasoning + brief, NOT retrieval) actually CONTAIN the gold
 * chapter? If recall@N is poor, the shortlist caps the broker low and the idea is
 * dead before we wire it into the descent — the same discipline as broker:chapter-
 * recall (which measured the retrieval ceiling at 84.7%@7). This costs ~3 LLM calls
 * per item (canonicalize + brief + propose), so it is NOT free — cap with --limit.
 */
class BrokerProposeRecall extends Command
{
    protected $signature = 'broker:propose-recall
        {--file=research-data/finetune/gold-split/test.jsonl : {name,gold 4-digit} JSONL}
        {--limit=100 : cap items (paid: ~3 LLM calls/item)}
        {--offset=0 : skip the first N items (for parallel sharding)}
        {--out=storage/app/broker-propose-recall.jsonl : per-item output}';

    protected $description = 'Measure the broker chapter-shortlist recall@N (model proposal vs gold chapter).';

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

        /** @var BrokerDescentMechanism $mech */
        $mech = app(BrokerDescentMechanism::class);
        $out = fopen(base_path((string) $this->option('out')), 'w');

        $maxN = (int) config('classify.broker.chapter_shortlist_n', 7);
        $hitsAtN = array_fill(1, $maxN, 0);
        $present = 0;
        $total = count($items);
        $i = 0;

        foreach ($items as $item) {
            $goldChap = substr((string) $item['gold'], 0, 2);
            try {
                $chapters = $mech->candidateChaptersFor((string) $item['name']);
            } catch (\Throwable $e) {
                $chapters = [];
            }

            $rank = array_search($goldChap, $chapters, true);
            $hit = $rank !== false;
            if ($hit) {
                $present++;
                for ($n = (int) $rank + 1; $n <= $maxN; $n++) {
                    $hitsAtN[$n]++;
                }
            }

            fwrite($out, json_encode([
                'name' => $item['name'], 'gold' => $item['gold'], 'gold_chapter' => $goldChap,
                'proposed' => $chapters, 'rank' => $hit ? (int) $rank + 1 : null,
            ], JSON_UNESCAPED_UNICODE)."\n");

            if (++$i % 20 === 0) {
                $this->output->write(sprintf("\r  %d/%d  recall@%d=%.1f%%   ", $i, $total, $maxN, 100 * $hitsAtN[$maxN] / $i));
            }
        }
        fclose($out);
        $this->newLine(2);

        $rows = [];
        for ($n = 1; $n <= $maxN; $n++) {
            $rows[] = ["recall@{$n}", sprintf('%.1f%%', $total ? 100 * $hitsAtN[$n] / $total : 0)];
        }
        $this->info("Broker shortlist chapter recall@N (gold chapter within the model's proposed list)");
        $this->table(['N', 'recall'], $rows);
        $this->line(sprintf('gold chapter present in list: %.1f%%  (the ceiling this shortlist imposes)',
            $total ? 100 * $present / $total : 0));

        return self::SUCCESS;
    }
}
