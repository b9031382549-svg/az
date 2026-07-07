<?php

namespace App\Services\Classify;

use App\Jobs\AdjudicateItemJob;
use App\Models\ClassificationItem;
use App\Models\ClassificationResult;
use Illuminate\Support\Collection;

/**
 * Reconciles the per-mechanism results of one item into a parent resolution.
 *
 * Policy: MAJORITY vote — a code auto-resolves when at least 2 independent
 * mechanisms agree on it (2-of-3, or unanimity when only 2 run); it tolerates one
 * dissenter/hallucination. Without a 2-vote majority the item goes to a human.
 *
 * resolution vocabulary:
 *   pending          — not every enabled mechanism has reported yet
 *   agreed           — a MAJORITY (>=2) returned the same code AND those are confident (auto)
 *   review           — a majority agreed on a code but at least one of them is not confident
 *   conflict         — no code reached a 2-mechanism majority (all divergent, or a lone code among abstentions)
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

        $this->maybeAdjudicate($item);
    }

    /**
     * Hand a DIVERGENT item (conflict / low-confidence review) to the AI
     * adjudicator — a side effect kept out of the pure resolve(). Dispatched at
     * most once per item: finalize() runs on every mechanism completion and on the
     * failed() path, so the adjudicated_at atomic claim is the single-fire guard.
     */
    private function maybeAdjudicate(ClassificationItem $item): void
    {
        $cfg = (array) config('classify.adjudicator');
        if (! ($cfg['enabled'] ?? false)) {
            return;
        }
        if (! in_array($item->resolution, (array) ($cfg['scope'] ?? ['review', 'conflict']), true)) {
            return;
        }

        // Genuinely underdetermined: a mechanism ABSTAINED and the ones that DID place
        // a code disagree across CHAPTERS — three methods could not converge on a
        // section. WITHOUT web search the arbiter could only pick a least-wrong code
        // from that shared premise, so it goes straight to a human. WITH web search
        // (a `:online` model) the arbiter has an INDEPENDENT premise — it looks the
        // item up — so let it try; it still returns "uncertain" when the search
        // doesn't settle it, and stability/holdout guards remain.
        $arbiterCanSearch = str_contains((string) ($cfg['model'] ?? ''), ':online');
        if ($item->resolution === 'conflict' && ! $arbiterCanSearch && $this->tooUncertainToAdjudicate($item)) {
            return;
        }

        $claimed = ClassificationItem::whereKey($item->id)
            ->whereNull('adjudicated_at')
            ->update(['adjudicated_at' => now()]);

        if ($claimed === 1) {
            AdjudicateItemJob::dispatch($item->id);
        }
    }

    /**
     * A conflict is too uncertain for the adjudicator when at least one mechanism
     * abstained AND the mechanisms that produced a code span more than one HS chapter
     * — no independent method could confirm even the section, so a human decides.
     */
    private function tooUncertainToAdjudicate(ClassificationItem $item): bool
    {
        $results = $item->results()->get();
        $abstained = $results->contains(fn ($r) => $r->matched_code === null || $r->matched_code === '');
        $chapters = $results
            ->filter(fn ($r) => $r->matched_code !== null && $r->matched_code !== '')
            ->map(fn ($r) => mb_substr((string) $r->matched_code, 0, 2))
            ->unique();

        return $abstained && $chapters->count() > 1;
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

        // MAJORITY vote by exact code: the winning code needs a strict majority of the
        // mechanisms that RAN — 1-of-1, 2-of-2, 2-of-3. Abstentions still count toward
        // the denominator (a lone code among abstentions is NOT a majority). Anything
        // short of a majority — all divergent, or too few agreeing — is a genuine
        // disagreement and goes to a human. A 3-way majority tolerates ONE dissenter
        // (e.g. a single mechanism's hallucination).
        $threshold = intdiv($results->count(), 2) + 1;
        $winner = $coded->groupBy('matched_code')->sortByDesc(fn ($g) => $g->count())->first();

        if ($winner->count() < $threshold) {
            return ['resolution' => 'conflict'] + $none;
        }

        $rep = $winner->first();
        $allConfident = $winner->every(fn ($r) => $r->status === 'auto_confirmed');

        return [
            'resolution' => $allConfident ? 'agreed' : 'review',
            'final_code' => $rep->matched_code,
            'final_catalog_id' => $rep->catalog_id,
            'kind' => $rep->kind,
        ];
    }
}
