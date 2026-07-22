<?php

namespace App\Services\Testing;

use App\Models\ClassificationItem;
use App\Services\Classify\Consensus;

/**
 * The per-item reconciler for a dataset test run — the exact analogue of
 * Consensus::finalize(), but the authoritative mechanism set and the search toggle
 * come from the RUN (its checkboxes) instead of global config, so we never mutate
 * config. The decision itself is prod code: Consensus::resolve() verbatim.
 *
 * Called by each mechanism job after it stores its result. It returns true when the
 * item resolved to a conflict that this run wants searched — the caller (which holds
 * the batch) then adds a ClassifyTestSearchJob. A single-fire claim on
 * search_resolved_at guarantees exactly one search per item under concurrency.
 */
class TestRunFinalizer
{
    public function __construct(private readonly Consensus $consensus) {}

    /**
     * @param  bool  $allowSearch  false on a hard-fail path — resolve the item but do NOT
     *                             claim/await a search (which would strand the run's
     *                             "settled?" check).
     * @return bool whether a search job should be dispatched for this item
     */
    public function finalize(ClassificationItem $item, bool $allowSearch = true): bool
    {
        $item->refresh();

        $run = $item->testRun;
        if ($run === null) {
            return false;
        }

        // A human/search-settled item is terminal — never recompute (mirrors prod).
        if ($item->resolution === 'confirmed' || $item->resolution === 'rejected' || $item->search_resolved_at !== null) {
            return false;
        }

        $mech = (array) $run->mechanisms;
        $authoritative = Consensus::computeAuthoritative(
            (array) ($mech['enabled'] ?? []),
            (array) ($mech['shadow'] ?? []),
        );

        $results = $item->results()->whereIn('mechanism', $authoritative)->get();
        if ($results->count() < count($authoritative)) {
            return false; // wait until every authoritative mechanism has reported
        }

        $item->update($this->consensus->resolve($results));

        if (! $allowSearch || $item->resolution !== 'conflict' || ! ($mech['search'] ?? false)) {
            return false;
        }

        // Single-fire: only the claim winner dispatches the paid :online search.
        $claimed = ClassificationItem::whereKey($item->id)
            ->whereNull('search_resolved_at')
            ->update(['search_resolved_at' => now()]);

        return $claimed === 1;
    }
}
