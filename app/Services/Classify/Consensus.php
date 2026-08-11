<?php

namespace App\Services\Classify;

use App\Jobs\SearchResolveJob;
use App\Models\ClassificationItem;
use App\Models\ClassificationResult;
use Illuminate\Support\Collection;

/**
 * Reconciles the per-mechanism results of one item into a parent resolution.
 *
 * Policy: our answer is the 4-digit HEADING. An item auto-resolves ONLY when every
 * mechanism that ran (vector / broker / direct) lands on the same first 4 characters —
 * that heading is taken as correct. Anything short of unanimity (including a bare
 * majority) leaves the heading undecided and the item is a `conflict`, handed to the
 * web-search resolver. There is no AI judge in this flow (removed for now).
 *
 * resolution vocabulary:
 *   pending          — not every enabled mechanism has reported yet
 *   agreed           — every mechanism that ran shares the same 4-digit heading (auto, confident)
 *   conflict         — the mechanisms did not unanimously agree (divergent, a bare majority, or too few)
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

        $this->maybePromote($item, $authResults);
        $this->maybeSearchResolve($item);
    }

    /**
     * Write a UNANIMOUS agreement back into the production memory so the same item later
     * resolves for free. PROD ONLY: test-run items (test_run_id set) reach TestRunFinalizer
     * rather than this method, and the guard is a second belt-and-suspenders check so an
     * irreversible write can never leak into the shared production cache from a test. The
     * unanimity gate + shadow/write policy lives in AnswerCacheService::promote().
     *
     * @param  Collection<int, ClassificationResult>  $authResults
     */
    private function maybePromote(ClassificationItem $item, Collection $authResults): void
    {
        // Only a REAL upload's unanimous item is promoted. Skip:
        //  - test-run items (test_run_id set) — they reach TestRunFinalizer, not this
        //    method; the guard is belt-and-suspenders on an irreversible write.
        //  - the benchmark eval path — `benchmark:seed` fans GOLD names through this same
        //    prod pipeline (batch "gold-<source>") purely to MEASURE accuracy, so those
        //    must never leak the gold set into the live memory.
        if ($item->test_run_id !== null
            || $item->resolution !== 'agreed'
            || str_starts_with((string) $item->batch, 'gold-')) {
            return;
        }

        app(AnswerCacheService::class)->promote($item, self::agreementOf($authResults));
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

        // Agreement is measured on the 4-digit HEADING, not the full code (see agreementOf).
        // The winning heading must be UNANIMOUS across every mechanism that ran (abstentions
        // count toward the denominator, so a lone code among abstentions is not unanimous).
        // Anything short of that — including a bare majority — is a conflict, routed to the
        // web-search resolver for a second opinion instead of auto-accepting.
        $ag = self::agreementOf($results);

        if ($ag['count'] === 0) {
            return ['resolution' => 'no_match'] + $none;
        }

        if ($ag['count'] < $ag['total']) {
            return ['resolution' => 'conflict'] + $none;
        }

        return [
            'resolution' => 'agreed',
            'final_code' => $ag['heading'],
            'final_catalog_id' => null,
            'kind' => $ag['kind'],
        ];
    }

    /**
     * How strongly a result set agrees on ONE 4-digit heading: the size of the largest
     * group of mechanisms sharing a heading (`count`), out of how many results there are
     * (`total`), plus that heading and its kind. Abstentions / error rows count toward the
     * denominator (as in resolve()), so a lone code among abstentions is not unanimous.
     * `count === total` (with `total >= 2`) is the UNANIMITY test used by memory promotion.
     *
     * @param  Collection<int, ClassificationResult>  $results
     * @return array{count: int, total: int, heading: ?string, kind: ?string}
     */
    public static function agreementOf(Collection $results): array
    {
        $coded = $results->filter(fn ($r) => $r->matched_code !== null && $r->matched_code !== '');

        if ($coded->isEmpty()) {
            return ['count' => 0, 'total' => $results->count(), 'heading' => null, 'kind' => null];
        }

        $winner = $coded
            ->groupBy(fn ($r) => mb_substr((string) $r->matched_code, 0, 4))
            ->sortByDesc(fn ($g) => $g->count())
            ->first();

        return [
            'count' => $winner->count(),
            'total' => $results->count(),
            'heading' => mb_substr((string) $winner->first()->matched_code, 0, 4),
            'kind' => $winner->first()->kind,
        ];
    }

    /**
     * Whether a candidate heading matches the matched_code (truncated to heading) of ANY
     * result in the given collection — the "grounding" check: does this answer overlap
     * with something an independent mechanism already proposed as a candidate, even
     * though it didn't win the original vote. Used to decide which search-resolved
     * (ai_resolved) answers are trusted enough for memory/training — see
     * AnswerCacheService::promoteGroundedSearch() and ExportFinetuneExamples. Measured
     * (3 independent prod test runs, ~900 pooled search-tier examples): grounded +
     * confidence >= 0.98 => 93-96% real accuracy; ungrounded => 34-58% (unstable).
     *
     * @param  Collection<int, ClassificationResult>  $results
     */
    public static function headingOverlaps(?string $heading, Collection $results): bool
    {
        if ($heading === null || $heading === '') {
            return false;
        }

        return $results->contains(fn ($r) => $r->matched_code !== null
            && mb_substr((string) $r->matched_code, 0, 4) === $heading);
    }
}
