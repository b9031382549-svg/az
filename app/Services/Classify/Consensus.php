<?php

namespace App\Services\Classify;

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
 *   no_match         — no mechanism produced a code
 *   confirmed/rejected — set by a human in the review queue (never overwritten here)
 *   blocked_on_fact  — set by the broker mechanism (Phase 7)
 */
class Consensus
{
    private const HUMAN_DECIDED = ['confirmed', 'rejected', 'blocked_on_fact'];

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

        $enabled = (array) config('classify.mechanisms.enabled', ['vector']);
        $shadow = (array) config('classify.mechanisms.shadow', []);

        // Shadow mechanisms run and are stored, but only the authoritative ones
        // drive the resolution — so a new mechanism can be measured before it
        // starts routing items to humans.
        $authoritative = array_values(array_diff($enabled, $shadow));
        if ($authoritative === []) {
            $authoritative = $enabled; // never shadow everything
        }

        $results = $item->results()->get();
        $authResults = $results->whereIn('mechanism', $authoritative)->values();

        if ($authResults->count() < count($authoritative)) {
            return; // stay 'pending' until every authoritative mechanism reports
        }

        $item->update($this->resolve($authResults));
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
