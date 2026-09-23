<?php

namespace App\Services\Classify;

use App\Models\ClassificationItem;
use App\Models\RubricatorNode;
use Illuminate\Support\Collection;

// Live progress of one classification batch for the "Classifying… N / M" panel shown after a
// Classify-page submit and after an invoice upload: how many items are done, whether the batch
// is complete, the latest rows and the names of the headings they were answered with.
class BatchProgress
{
    /**
     * @return array{done: int, count: int, complete: bool, rows: Collection, headingNames: Collection}
     */
    public function for(string $batch, int $count): array
    {
        // "Done" is every non-pending row — EXCEPT a raw 'conflict' that the web-search
        // resolver hasn't finished yet. Consensus sets 'conflict' and only *then*
        // dispatches SearchResolveJob, so a just-diverged item is non-pending while its
        // resolver job is still queued/running. Counting it as done let the bar hit 100%
        // and stop polling (blade: wire:poll only while !complete) before the resolver
        // flipped those rows to 'ai_resolved' — "finished" on screen, still working in
        // fact. A completed resolve *always* leaves a mechanism='search' trace row (the
        // same signal the reaper trusts), so its presence is the real "resolver done"
        // marker; search_resolved_at is only the dispatch claim, not completion.
        $resolverEnabled = (bool) config('classify.search_resolver.enabled', false);
        $done = ClassificationItem::where('batch', $batch)
            ->where('resolution', '!=', 'pending')
            ->when($resolverEnabled, fn ($q) => $q->where(fn ($w) => $w
                ->where('resolution', '!=', 'conflict')
                ->orWhereHas('results', fn ($r) => $r->where('mechanism', 'search'))))
            ->count();

        $rows = ClassificationItem::where('batch', $batch)
            ->with(['finalCode', 'translation', 'results'])
            ->latest()
            ->limit(50)
            ->get();

        // A 4-digit heading (or "99") answer has no exact catalog leaf — resolve its
        // display name from the rubricator (same source ReviewQueue uses).
        $headingCodes = $rows->pluck('final_code')
            ->filter(fn ($c) => ($n = mb_strlen((string) $c)) > 0 && $n < 10)->unique()->values();
        $headingNames = RubricatorNode::whereIn('code', $headingCodes)->get(['code', 'title', 'title_en', 'title_ru'])
            ->mapWithKeys(fn ($n) => [(string) $n->code => $n->localizedTitle()]);

        return [
            'done' => $done,
            'count' => $count,
            'complete' => $done >= $count,
            'rows' => $rows,
            'headingNames' => $headingNames,
        ];
    }
}
