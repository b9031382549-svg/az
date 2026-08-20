<?php

namespace App\Console\Commands;

use App\Services\Classify\Mechanisms\BrokerDescentMechanism;
use App\Services\Classify\Mechanisms\DirectLlmMechanism;
use App\Services\Classify\Mechanisms\VectorMechanism;
use Illuminate\Console\Command;

/**
 * Run all three mechanisms per item and log the raw signals needed to SIMULATE
 * different consensus rules offline (no more LLM): the vector's top-K candidate
 * headings, and the broker/direct final picks — plus the gold heading. Lets us
 * compare "strict unanimity (vector top-1)" vs "broker==direct ∈ vector-top5" vs
 * "direct ∈ vector-top5" etc. on accuracy AND coverage, from one paid run.
 */
class ClassifyEnsembleLog extends Command
{
    protected $signature = 'classify:ensemble-log
        {--file=research-data/finetune/gold-split/test.jsonl : {name,gold} JSONL}
        {--limit=0 : cap items (after offset)}
        {--offset=0 : shard offset}
        {--topk=5 : distinct vector headings to log}
        {--out=storage/app/ensemble-log.jsonl : per-item output}';

    protected $description = 'Run vector+broker+direct per item; log vector top-K headings + broker/direct picks for offline consensus simulation.';

    public function handle(VectorMechanism $vec, BrokerDescentMechanism $brok, DirectLlmMechanism $dir): int
    {
        $all = [];
        foreach (file(base_path((string) $this->option('file')), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if (isset($r['name'], $r['gold'])) {
                $all[] = $r;
            }
        }
        $limit = (int) $this->option('limit');
        $items = array_slice($all, (int) $this->option('offset'), $limit > 0 ? $limit : null);
        $topk = max(1, (int) $this->option('topk'));

        $head = fn (?string $code) => $code !== null ? substr($code, 0, 4) : null;
        $out = fopen(base_path((string) $this->option('out')), 'w');
        $i = 0;

        foreach ($items as $item) {
            $name = (string) $item['name'];
            $vr = $this->safe(fn () => $vec->classify($name));
            $br = $this->safe(fn () => $brok->classify($name));
            $dr = $this->safe(fn () => $dir->classify($name));

            // Distinct vector candidate headings, in order, top-K.
            $vHeads = [];
            foreach (($vr?->candidates ?? []) as $c) {
                $h = $head(is_array($c) ? ($c['code'] ?? null) : ($c->code ?? null));
                if ($h !== null && ! in_array($h, $vHeads, true)) {
                    $vHeads[] = $h;
                }
                if (count($vHeads) >= $topk) {
                    break;
                }
            }

            fwrite($out, json_encode([
                'gold' => (string) $item['gold'],
                'vec' => $head($vr?->matchedCode),
                'vec5' => $vHeads,
                'brok' => $head($br?->matchedCode),
                'dir' => $head($dr?->matchedCode),
            ], JSON_UNESCAPED_UNICODE)."\n");

            if (++$i % 20 === 0) {
                $this->output->write("\r  {$i}/".count($items).'   ');
            }
        }
        fclose($out);
        $this->newLine();
        $this->info('Wrote '.count($items).' rows → '.$this->option('out'));

        return self::SUCCESS;
    }

    private function safe(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
