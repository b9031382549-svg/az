<?php

namespace App\Console\Commands;

use App\Models\ItemTranslation;
use Illuminate\Console\Command;

/**
 * Build the "AI-normalized query" test file: for each held-out item, look up the
 * production ProductBrief by source_hash and use its clean `identity` as the query
 * (exactly what VectorMechanism seeds retrieval with). Items with no cached brief
 * fall back to the raw name. Output is a drop-in {name,gold} JSONL — where `name`
 * is now the brief identity — so classify:vector-baseline runs on it unchanged.
 *
 * Lets us fill the 2x2: stock/FT × raw/brief, reusing prod briefs (no LLM re-run).
 */
class FinetuneBuildBriefQueries extends Command
{
    protected $signature = 'finetune:build-brief-queries
        {--test=research-data/finetune/gold-split/test.jsonl : {name,gold} JSONL}
        {--briefs=research-data/finetune/contrastive/prod_briefs.jsonl : {h,identity,ok} from prod}
        {--out=research-data/finetune/contrastive/test_brief.jsonl : output {name=identity-or-raw, gold}}';

    protected $description = 'Build brief-identity query file (matched to prod ProductBriefs by source_hash) for the 2x2 comparison.';

    public function handle(): int
    {
        $briefPath = base_path((string) $this->option('briefs'));
        $testPath = base_path((string) $this->option('test'));
        $outPath = base_path((string) $this->option('out'));

        // source_hash -> identity (only usable briefs with a non-empty identity).
        $byHash = [];
        foreach (file($briefPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $b = json_decode($line, true);
            $id = trim((string) ($b['identity'] ?? ''));
            if (isset($b['h']) && $id !== '') {
                $byHash[$b['h']] = $id;
            }
        }
        $this->info(count($byHash).' usable briefs indexed by source_hash.');

        $fh = fopen($outPath, 'w');
        $total = 0;
        $withBrief = 0;
        foreach (file($testPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode($line, true);
            if (! isset($row['name'], $row['gold'])) {
                continue;
            }
            $total++;
            $hash = ItemTranslation::hashFor((string) $row['name']);
            $identity = $byHash[$hash] ?? null;
            if ($identity !== null) {
                $withBrief++;
            }

            fwrite($fh, json_encode([
                'name' => $identity ?? $row['name'], // query = brief identity, else raw
                'gold' => (string) $row['gold'],
                'used_brief' => $identity !== null,
            ], JSON_UNESCAPED_UNICODE)."\n");
        }
        fclose($fh);

        $pct = $total ? round(100 * $withBrief / $total, 1) : 0;
        $this->info("Wrote {$total} queries → {$outPath}");
        $this->line("Matched a prod brief for {$withBrief}/{$total} ({$pct}%); the rest fell back to raw name.");

        return self::SUCCESS;
    }
}
