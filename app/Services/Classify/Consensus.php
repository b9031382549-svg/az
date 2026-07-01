<?php

namespace App\Services\Classify;

use App\Models\ClassificationItem;
use App\Models\ClassificationResult;
use Illuminate\Support\Collection;

/**
 * Reconciles the per-mechanism results of one item into a parent resolution.
 *
 * Policy (max accuracy — chosen by the product): mechanisms auto-resolve only
 * when they FULLY agree; any divergence goes to a human.
 *
 * resolution vocabulary:
 *   pending          — not every enabled mechanism has reported yet
 *   agreed           — all mechanisms returned the same code AND all are confident (auto)
 *   review           — all mechanisms agreed on a code but at least one is not confident
 *   conflict         — mechanisms returned different codes, or one found a code while another abstained
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
        $results = $item->results()->get();

        if ($results->count() < count($enabled)) {
            return; // stay 'pending' until all mechanisms report
        }

        $item->update($this->resolve($results));
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

        $distinctCodes = $coded->pluck('matched_code')->unique();
        $abstained = $results->count() - $coded->count();

        // Any divergence — differing codes, or one mechanism found a code while
        // another abstained — is sent to a human.
        if ($distinctCodes->count() > 1 || $abstained > 0) {
            return ['resolution' => 'conflict'] + $none;
        }

        $rep = $coded->first();
        $allConfident = $coded->every(fn ($r) => $r->status === 'auto_confirmed');

        return [
            'resolution' => $allConfident ? 'agreed' : 'review',
            'final_code' => $rep->matched_code,
            'final_catalog_id' => $rep->catalog_id,
            'kind' => $rep->kind,
        ];
    }
}
