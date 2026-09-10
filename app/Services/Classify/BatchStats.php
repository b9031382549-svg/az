<?php

namespace App\Services\Classify;

use App\Models\ClassificationItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Always-on per-batch classification statistics — the funnel Roman asked for: how many of a
 * batch's items each step resolved (Memory → Local AI / Ensemble → Web search → Human), the
 * share of each, how many of those answers were written back to memory & training, and the
 * total recognition time (start → every item answered).
 *
 * "Source" is derived from the trace rows, not the resolution alone, because resolution
 * conflates them: a cache hit and a consensus agreement are both 'agreed', and an ensemble
 * and a web verdict are both 'ai_resolved'. The split mirrors the results-table $sourceOf
 * and Consensus::resolve. Timing comes from the set-once answered_at (never moved by a later
 * human edit), so it stays honest.
 */
class BatchStats
{
    /**
     * @return array<string, mixed>
     */
    public function for(string $batch): array
    {
        $base = fn (): Builder => ClassificationItem::query()
            ->where('batch', $batch)
            ->whereNull('test_run_id');

        $total = $base()->count();

        // Ensemble-sourced = an 'ai_resolved' item whose committed 'ensemble' trace IS the
        // final answer (otherwise the web search is what settled it).
        $ensembleSourced = fn (Builder $q): Builder => $q->whereHas('results', fn ($r) => $r
            ->where('mechanism', 'ensemble')
            ->whereColumn('matched_code', 'classification_items.final_code'));

        $answered = $base()->whereNotNull('answered_at')->count();
        $agreed = $base()->where('resolution', 'agreed')->count();
        $memory = $base()->whereHas('results', fn ($r) => $r->where('mechanism', 'cache'))->count();
        $localAi = max(0, $agreed - $memory);
        $aiResolved = $base()->where('resolution', 'ai_resolved')->count();
        $ensemble = $ensembleSourced($base()->where('resolution', 'ai_resolved'))->count();
        $web = max(0, $aiResolved - $ensemble);
        // Everything else that reached an outcome (a done conflict/no_match, or a human
        // confirm/reject) is the human-review bucket — computed as the remainder so the rows
        // always sum to the answered total and never double-count an in-flight conflict.
        $human = max(0, $answered - $memory - $localAi - $ensemble - $web);
        $processing = max(0, $total - $answered);

        // Sent to memory & training — what ACTUALLY went to answer_cache (memory_promoted_at),
        // grouped by the same source. Cache hits are already in memory (n/a); ensemble commits
        // are deliberately not promoted (so that column is honestly ~0); web is the grounded,
        // high-confidence subset; human is the confirmed write-back.
        $agreedPromoted = $base()->where('resolution', 'agreed')->whereNotNull('memory_promoted_at')->count();
        $aiPromoted = $base()->where('resolution', 'ai_resolved')->whereNotNull('memory_promoted_at')->count();
        $ensemblePromoted = $ensembleSourced($base()->where('resolution', 'ai_resolved')->whereNotNull('memory_promoted_at'))->count();
        $webPromoted = max(0, $aiPromoted - $ensemblePromoted);
        $humanPromoted = $base()->where('resolution', 'confirmed')->whereNotNull('memory_promoted_at')->count();

        $pct = fn (int $n): float => $total > 0 ? round($n / $total * 100, 1) : 0.0;

        $rows = [
            ['step' => 1, 'key' => 'memory', 'label' => 'Memory', 'ran' => $memory, 'pct' => $pct($memory), 'memory' => null],
            ['step' => 2, 'key' => 'local_ai', 'label' => 'Local AI (Vector + Direct)', 'ran' => $localAi, 'pct' => $pct($localAi), 'memory' => $agreedPromoted],
            ['step' => 2, 'key' => 'ensemble', 'label' => 'Ensemble (Vector + Direct + Web)', 'ran' => $ensemble, 'pct' => $pct($ensemble), 'memory' => $ensemblePromoted],
            ['step' => 3, 'key' => 'web', 'label' => 'Web search', 'ran' => $web, 'pct' => $pct($web), 'memory' => $webPromoted],
            ['step' => 4, 'key' => 'human', 'label' => 'Human review', 'ran' => $human, 'pct' => $pct($human), 'memory' => null],
        ];

        // Recognition time: start of the batch → the moment the LAST item was answered. Uses
        // answered_at (set once, never moved by a later human edit), so it measures the machine
        // pipeline, not review activity. Null while nothing has finished yet.
        $startedAt = $base()->min('created_at');
        $lastAnsweredAt = $base()->max('answered_at');
        $seconds = ($startedAt && $lastAnsweredAt)
            ? max(0, strtotime((string) $lastAnsweredAt) - strtotime((string) $startedAt))
            : null;

        return [
            'total' => $total,
            'answered' => $answered,
            'processing' => $processing,
            'complete' => $processing === 0 && $total > 0,
            'rows' => $rows,
            'memory_promoted' => $agreedPromoted + $aiPromoted + $humanPromoted,
            'web_count' => $web,
            'web_pct' => $pct($web),
            'human_count' => $human,
            'started_at' => $startedAt,
            'last_answered_at' => $lastAnsweredAt,
            'seconds' => $seconds,
        ];
    }
}
