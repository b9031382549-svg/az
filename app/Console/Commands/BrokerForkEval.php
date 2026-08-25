<?php

namespace App\Console\Commands;

use App\Services\Classify\Mechanisms\BrokerDescentMechanism;
use App\Services\Classify\Mechanisms\MechanismResult;
use Illuminate\Console\Command;

/**
 * Per-FORK diagnostic for the broker. `broker:eval` scores the broker's final
 * answer post-hoc; this instead runs the broker LIVE over a {name,gold} set and
 * asks WHERE a wrong answer left the gold path — the root chapter fork, a deeper
 * heading fork, or a fallback/abstain. The broker is a greedy top-1 descent, so a
 * confident-wrong fork upstream is unrecoverable; this tells you which fork to fix
 * (retrieval prior at the root vs beam/backtracking mid-descent vs calibration)
 * instead of tuning it blind.
 *
 * gold is a 4-digit heading (same file as classify:mechanism-eval), so accuracy is
 * measured at the chapter (2-digit) and heading (4-digit) levels — the two forks
 * that carry the most error and the most cost.
 */
class BrokerForkEval extends Command
{
    protected $signature = 'broker:fork-eval
        {--file=research-data/finetune/gold-split/test.jsonl : {name,gold} JSONL (gold = 4-digit heading)}
        {--limit=200 : cap items (applied AFTER offset). 0 = the FULL set (expensive: ~5-8 LLM calls/item)}
        {--offset=0 : skip the first N items (for parallel sharding)}
        {--shortlist : force-enable broker.chapter_shortlist for this run (A/B the shortlist)}
        {--glossary : force-enable broker.brief_glossary for this run (A/B the catalog glossary)}
        {--out=storage/app/broker-fork-eval.jsonl : per-item output for drill-down}';

    protected $description = 'Run the broker live over a gold set; report per-fork accuracy and WHERE wrong answers left the gold path.';

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

        if ($this->option('shortlist')) {
            config(['classify.broker.chapter_shortlist' => true]);
            $this->info('chapter_shortlist = ON');
        }
        if ($this->option('glossary')) {
            config(['classify.broker.brief_glossary' => true]);
            $this->info('brief_glossary = ON');
        }

        $total = count($items);
        $this->warn(sprintf(
            '%d items × ~5-8 strong-model calls ≈ %d-%d paid LLM calls. Ctrl-C now to cap with --limit.',
            $total, $total * 5, $total * 8
        ));

        /** @var BrokerDescentMechanism $mech */
        $mech = app(BrokerDescentMechanism::class);
        $out = fopen(base_path((string) $this->option('out')), 'w');

        // Outcome buckets — every item lands in exactly one.
        $buckets = [
            'correct' => 0,          // final 4-digit == gold
            'wrong_chapter' => 0,    // root fork picked the wrong chapter (unrecoverable)
            'wrong_heading' => 0,    // chapter right, diverged deeper (heading/leaf/fallback)
            'abstain' => 0,          // no chapter established — honest abstain at root
            'no_match' => 0,         // reached a prefix but returned no code
            'error' => 0,            // exception
        ];
        $chapterCorrect = 0;   // final chapter == gold chapter (regardless of heading)
        $confidentWrong = 0;   // wrong AND auto_confirmed — the dangerous calibration failures
        $wrongViaFallback = 0; // wrong AND the answer came from the retrieval fallback, not a clean descent

        $i = 0;
        foreach ($items as $item) {
            $gold = (string) $item['gold'];
            $goldChap = substr($gold, 0, 2);
            $err = null;
            $res = null;
            try {
                $res = $mech->classify((string) $item['name']);
            } catch (\Throwable $e) {
                $err = $e->getMessage();
            }

            $briefId = null;
            $briefFn = null;
            $briefAxis = null;
            if ($err !== null) {
                $buckets['error']++;
                $bucket = 'error';
                $finalHead = null;
                $descentChap = null;
                $viaFallback = false;
                $status = null;
                $confidence = null;
            } else {
                // The brief's product understanding — lets us later split errors into
                // cat.1 (brief mis-identified the product) vs cat.2 (product understood,
                // wrong legal boundary), which is what the customs-"why" test hinges on.
                $brief = $res->trace['brief'] ?? null;
                if (is_array($brief)) {
                    $briefId = $brief['identity'] ?? null;
                    $briefFn = $brief['function_class'] ?? null;
                    $briefAxis = $brief['decisive_axis'] ?? null;
                }
                [$descentChap, $viaFallback] = $this->descentInfo($res);
                $finalCode = $res->matchedCode;
                $finalHead = $finalCode !== null ? substr($finalCode, 0, 4) : null;
                $finalChap = $finalCode !== null ? substr($finalCode, 0, 2) : $descentChap;
                $status = $res->status;
                $confidence = $res->confidence;

                if ($finalChap !== null && $finalChap === $goldChap) {
                    $chapterCorrect++;
                }

                if ($finalHead !== null && $finalHead === $gold) {
                    $bucket = 'correct';
                } elseif ($finalCode === null) {
                    // Abstained at the root (no chapter) vs reached a prefix but no code.
                    $bucket = $descentChap === null ? 'abstain' : 'no_match';
                } elseif ($finalChap !== $goldChap) {
                    $bucket = 'wrong_chapter';
                } else {
                    $bucket = 'wrong_heading';
                }
                $buckets[$bucket]++;

                if ($bucket !== 'correct' && $bucket !== 'abstain' && $bucket !== 'no_match') {
                    if ($status === 'auto_confirmed') {
                        $confidentWrong++;
                    }
                    if ($viaFallback) {
                        $wrongViaFallback++;
                    }
                }
            }

            fwrite($out, json_encode([
                'name' => $item['name'], 'gold' => $gold,
                'head' => $finalHead, 'chapter' => $descentChap,
                'bucket' => $bucket, 'status' => $status, 'confidence' => $confidence,
                'via_fallback' => $viaFallback ?? null,
                'brief_identity' => $briefId, 'brief_fn' => $briefFn, 'brief_axis' => $briefAxis,
                'err' => $err,
            ], JSON_UNESCAPED_UNICODE)."\n");

            if (++$i % 10 === 0) {
                $this->output->write(sprintf(
                    "\r  %d/%d  chap=%.0f%%  head=%.0f%%   ",
                    $i, $total, 100 * $chapterCorrect / $i, 100 * $buckets['correct'] / $i
                ));
            }
        }
        fclose($out);
        $this->newLine(2);

        $pct = fn (int $n) => sprintf('%d (%.1f%%)', $n, $total ? 100 * $n / $total : 0);

        $this->info('ANSWER FUNNEL');
        $this->table(['level', 'correct / total'], [
            ['chapter (2-digit)', $pct($chapterCorrect)],
            ['heading (4-digit)', $pct($buckets['correct'])],
        ]);

        $this->info('WHERE WRONG ANSWERS LEFT THE GOLD PATH');
        $this->table(['bucket', 'count', 'meaning'], [
            ['correct', $pct($buckets['correct']), 'final heading == gold'],
            ['wrong_chapter', $pct($buckets['wrong_chapter']), 'ROOT fork wrong — unrecoverable (→ retrieval prior)'],
            ['wrong_heading', $pct($buckets['wrong_heading']), 'chapter right, diverged deeper (→ beam / leaf)'],
            ['abstain', $pct($buckets['abstain']), 'no chapter established — honest abstain'],
            ['no_match', $pct($buckets['no_match']), 'reached a prefix, returned no code'],
            ['error', $pct($buckets['error']), 'exception'],
        ]);

        $wrong = $buckets['wrong_chapter'] + $buckets['wrong_heading'];
        $this->info('WRONG-ANSWER QUALITY (of '.$wrong.' wrong-code answers)');
        $this->table(['metric', 'value'], [
            ['confident-wrong (auto_confirmed)', $wrong ? sprintf('%d (%.1f%%)', $confidentWrong, 100 * $confidentWrong / $wrong) : '0'],
            ['wrong via fallback', $wrong ? sprintf('%d (%.1f%%)', $wrongViaFallback, 100 * $wrongViaFallback / $wrong) : '0'],
        ]);
        $this->line('Per-item drill-down: '.$this->option('out'));

        return self::SUCCESS;
    }

    /**
     * From the broker's descent path: the chapter (2-digit) it actually descended
     * into, and whether the final answer came from the retrieval fallback rather
     * than a clean top-down descent.
     *
     * @return array{0: ?string, 1: bool} [chapterCode, viaFallback]
     */
    private function descentInfo(MechanismResult $res): array
    {
        $chapter = null;
        $viaFallback = false;
        foreach ($res->path as $step) {
            $by = $step['by'] ?? null;
            if ($by === 'fallback' || $by === 'abstain') {
                $viaFallback = $by === 'fallback';
            }
            // The first 2-digit rubricator node the descent fixed = the chapter.
            $code = isset($step['code']) ? (string) $step['code'] : '';
            if ($chapter === null && strlen($code) === 2 && in_array($by, ['decided', 'only-child'], true)) {
                $chapter = $code;
            }
        }

        return [$chapter, $viaFallback];
    }
}
