<?php

namespace App\Services\Classify;

use App\Jobs\SearchResolveJob;
use App\Models\ClassificationItem;
use App\Models\ClassificationResult;
use Illuminate\Support\Collection;

/**
 * Reconciles the per-mechanism results of one item into a parent resolution.
 *
 * Policy: our answer is the 4-digit HEADING. An item auto-resolves when at least 2 of
 * the 3 mechanisms (vector / broker / direct) land on the same first 4 characters —
 * that heading is taken as correct. Short of a 2-mechanism agreement the heading is
 * undecided and the item is a `conflict`, handed to the next stage of the flow (TBD).
 * There is no AI judge in this flow (removed for now).
 *
 * resolution vocabulary:
 *   pending          — not every enabled mechanism has reported yet
 *   agreed           — >=2 mechanisms share the same 4-digit heading (auto, confident)
 *   conflict         — no heading reached a 2-mechanism agreement (divergent / too few)
 *   ai_resolved      — a divergent item the SEARCH resolver settled at a 4-digit heading
 *   no_match         — no mechanism produced a code
 *   confirmed/rejected — set by a human in the review queue (never overwritten here)
 *   blocked_on_fact  — set by the broker mechanism (Phase 7)
 *
 * When the mechanisms diverge ('conflict') a web-search resolver is dispatched once
 * (SearchResolveJob) — a confident hit flips the item to 'ai_resolved', otherwise it
 * stays 'conflict' for a human.
 */
class Consensus
{
    private const HUMAN_DECIDED = ['confirmed', 'rejected', 'blocked_on_fact'];

    /**
     * The mechanisms that drive the resolution: the enabled set minus the shadow
     * set (never all-shadow — a run that shadows everything still resolves on the
     * enabled set). Shared with the dataset test runner so a test run reproduces
     * prod's authoritative selection exactly instead of re-deriving it.
     *
     * @param  array<int, string>  $enabled
     * @param  array<int, string>  $shadow
     * @return array<int, string>
     */
    public static function computeAuthoritative(array $enabled, array $shadow): array
    {
        $authoritative = array_values(array_diff($enabled, $shadow));

        return $authoritative === [] ? array_values($enabled) : $authoritative;
    }

    /**
     * Recompute and persist the item's resolution once every enabled mechanism
     * has reported. Safe to call after each mechanism finishes (idempotent) and
     * never overwrites a human/terminal decision.
     */
    public function finalize(ClassificationItem $item): void
    {
        $item->refresh();

        if (in_array($item->resolution, self::HUMAN_DECIDED, true)) {
            return;
        }

        // Once the search resolver has claimed a conflict, leave the item alone — a late
        // finalize() (e.g. a mechanism's failed() path) must not recompute 'conflict'
        // over an item the resolver already settled to 'ai_resolved'.
        if ($item->search_resolved_at !== null) {
            return;
        }

        // Shadow mechanisms run and are stored, but only the authoritative ones
        // drive the resolution — so a new mechanism can be measured before it
        // starts routing items to humans.
        $authoritative = self::computeAuthoritative(
            (array) config('classify.mechanisms.enabled', ['vector']),
            (array) config('classify.mechanisms.shadow', []),
        );

        $results = $item->results()->get();
        $authResults = $results->whereIn('mechanism', $authoritative)->values();

        if ($authResults->count() < count($authoritative)) {
            return; // stay 'pending' until every authoritative mechanism reports
        }

        $item->update($this->resolve($authResults));

        $this->maybeSearchResolve($item);
    }

    /**
     * Hand a DIVERGENT ('conflict') item to the web-search resolver — a side effect kept
     * out of the pure resolve(). Dispatched at most once per item: finalize() runs on
     * every mechanism completion and on the failed() path, so the search_resolved_at
     * atomic claim is the single-fire guard.
     */
    private function maybeSearchResolve(ClassificationItem $item): void
    {
        if ($item->resolution !== 'conflict' || ! (bool) config('classify.search_resolver.enabled', false)) {
            return;
        }

        $claimed = ClassificationItem::whereKey($item->id)
            ->whereNull('search_resolved_at')
            ->update(['search_resolved_at' => now()]);

        if ($claimed === 1) {
            SearchResolveJob::dispatch($item->id);
        }
    }

    /**
     * Pure reconciliation of a result set into resolution + final code.
     *
     * @param  Collection<int, ClassificationResult>  $results
     * @return array{resolution: string, final_code: ?string, final_catalog_id: ?int, kind: ?string}
     */
    public function resolve(Collection $results): array
    {
        $none = ['final_code' => null, 'final_catalog_id' => null, 'kind' => null];

        $coded = $results->filter(fn ($r) => $r->matched_code !== null && $r->matched_code !== '');

        if ($coded->isEmpty()) {
            return ['resolution' => 'no_match'] + $none;
        }

        // Agreement is measured on the 4-digit HEADING, not the full code: group the
        // mechanisms by the first 4 characters of their code and take the largest group.
        // It wins with a strict MAJORITY of the mechanisms that ran — for the 3-mechanism
        // flow that is exactly "2 of 3" (abstentions count toward the denominator, so a
        // lone code among abstentions is not a majority). Anything short → conflict.
        $threshold = intdiv($results->count(), 2) + 1;
        $winner = $coded
            ->groupBy(fn ($r) => mb_substr((string) $r->matched_code, 0, 4))
            ->sortByDesc(fn ($g) => $g->count())
            ->first();

        if ($winner->count() < $threshold) {
            return ['resolution' => 'conflict'] + $none;
        }

        // The answer is the shared heading itself (4 digits) — not any one mechanism's
        // deeper code, which we no longer chase.
        $heading = mb_substr((string) $winner->first()->matched_code, 0, 4);

        return [
            'resolution' => 'agreed',
            'final_code' => $heading,
            'final_catalog_id' => null,
            'kind' => $winner->first()->kind,
        ];
    }
}
