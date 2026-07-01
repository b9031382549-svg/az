<?php

namespace App\Console\Commands;

use App\Services\Classify\BrokerEvaluator;
use Illuminate\Console\Command;

/**
 * Reports how each mechanism does against the human-confirmed gold set: accuracy
 * at the 10/6/4/2-digit levels, coverage, token cost, confidence-vs-accuracy
 * (calibration), and inter-mechanism agreement. Run it while the broker is in
 * shadow mode to decide the auto-confirm threshold before it goes authoritative.
 */
class BrokerEval extends Command
{
    protected $signature = 'broker:eval {--mechanism=* : Mechanisms to evaluate (default: enabled)}';

    protected $description = 'Evaluate mechanisms against human-confirmed items';

    public function handle(BrokerEvaluator $evaluator): int
    {
        $mechanisms = $this->option('mechanism') ?: (array) config('classify.mechanisms.enabled', ['vector']);
        $report = $evaluator->evaluate($mechanisms);

        if ($report['total'] === 0) {
            $this->warn('No classified items yet — classify a batch first.');

            return self::SUCCESS;
        }

        // Overall (shadow) signal — needs no ground truth.
        $this->info("Overall: {$report['total']} items classified.");
        $resline = collect($report['resolutions'])->map(fn ($c, $r) => "{$r}={$c}")->implode('  ');
        $this->line('  resolutions: '.$resline);
        foreach ($report['mechanisms'] as $mech => $m) {
            $this->line("  {$mech}: produced a code for {$m['coverageAll']} items");
        }
        if ($report['agreement'] !== null) {
            $a = $report['agreement'];
            $pct = $a['both'] > 0 ? round($a['match'] / $a['both'] * 100).'%' : '—';
            $this->line("  agreement {$a['a']} vs {$a['b']}: {$a['match']}/{$a['both']} ({$pct}) — the rest are conflicts a human resolves.");
        }
        $this->newLine();

        if ($report['sampleSize'] === 0) {
            $this->warn('No human-confirmed items yet — confirm some in the review queue to measure accuracy.');

            return self::SUCCESS;
        }

        $this->info("Gold set: {$report['sampleSize']} human-confirmed items.");
        $this->newLine();

        $rows = [];
        foreach ($report['mechanisms'] as $mech => $m) {
            $pct = fn (int $x) => $m['coverage'] > 0 ? round($x / $m['coverage'] * 100).'%' : '—';
            $rows[] = [$mech, $m['coverage'], $pct($m['exact']), $pct($m['p6']), $pct($m['p4']), $pct($m['p2']), $m['avgTokens']];
        }
        $this->table(['mechanism', 'coverage', 'exact (10)', '6-digit', '4-digit', 'chapter', 'avg tok'], $rows);

        foreach ($report['mechanisms'] as $mech => $m) {
            $this->newLine();
            $this->line("<info>{$mech}</info> — confidence vs exact accuracy (calibration):");
            foreach ($m['buckets'] as $b) {
                $acc = $b['n'] > 0 ? round($b['exact'] / $b['n'] * 100).'%' : '—';
                $this->line("  conf {$b['label']}:  {$b['exact']}/{$b['n']} exact  ({$acc})");
            }
        }

        return self::SUCCESS;
    }
}
