<?php

namespace App\Services\Testing;

use App\Models\ClassificationItem;
use App\Models\TestRun;
use App\Services\Classify\AnswerCacheService;
use App\Services\Classify\Consensus;
use App\Services\Classify\HeadingMatch;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Turns a finished run's stored classification_results into per-mechanism accuracy.
 *
 * Denominators FLOAT by design (we replicate the prod short path): a mechanism's
 * `ran` counts only the rows where it actually executed — memory runs on all rows,
 * vector/broker/direct only on cache-misses, search only on conflicts. To measure a
 * mechanism over ALL rows, run with memory off (then every row reaches the mechanism
 * stage). Everything is scored at the 4-digit heading via the shared HeadingMatch.
 */
class RunScorer
{
    /** mechanism-column => classification_results.mechanism row key */
    private const MECHANISM_COLUMNS = [
        'memory' => 'cache',
        'vector' => 'vector',
        'broker' => 'broker',
        'direct' => 'direct',
        'search' => 'search',
    ];

    public function __construct(
        private readonly Consensus $consensus,
        private readonly AnswerCacheService $answerCache,
    ) {}

    /**
     * Compute accuracy, persist it, and mark the run done — but ONLY once every item is
     * settled. Dispatched from several places (the batch's finally, a hard-fail
     * re-trigger); the guard makes a premature or duplicate call a harmless no-op, so the
     * persisted score always reflects the fully-classified run.
     */
    public function finalize(TestRun $run): void
    {
        $run->refresh();
        if ($run->status === 'done' || ! $this->isSettled($run)) {
            return;
        }

        $run->update([
            'accuracy' => $this->score($run),
            'status' => 'done',
            'finished_at' => now(),
        ]);
    }

    /**
     * How many of the run's items are FULLY classified — the progress-bar numerator. A
     * row is done only once it has left 'pending' AND, if it hit a conflict that went to
     * the web search, that search has come back. So the bar tracks the whole pipeline and
     * reaches 100% exactly when the run settles — instead of jumping there the moment the
     * vote mechanisms (vector/broker/direct) finish while the slow search tie-break is
     * still grinding through the conflicts.
     */
    public function doneCount(TestRun $run): int
    {
        return $run->items()
            ->where('resolution', '!=', 'pending')
            ->whereNot(fn ($q) => $this->constrainMidSearch($q))
            ->count();
    }

    /** Every item has a terminal resolution AND no conflict is still awaiting its search. */
    private function isSettled(TestRun $run): bool
    {
        if ($run->items()->where('resolution', 'pending')->exists()) {
            return false;
        }

        return ! $run->items()->where(fn ($q) => $this->constrainMidSearch($q))->exists();
    }

    /**
     * A conflict that claimed a search (search_resolved_at set) but has no 'search' result
     * row yet — still mid-search. Shared by isSettled() and doneCount() so the progress bar
     * and the scorer's settle-guard can never drift.
     *
     * @param  Builder<ClassificationItem>  $query
     */
    private function constrainMidSearch($query): void
    {
        $query->where('resolution', 'conflict')
            ->whereNotNull('search_resolved_at')
            ->whereDoesntHave('results', fn ($q) => $q->where('mechanism', 'search'));
    }

    /**
     * @return array{columns: array<string, array{ran:int, answered:int, correct:int}>, total:int, tokens:int, funnel: array{total:int, prevote: array<int, array{ran:int, answered:int, correct:int, promoted:int}>, search_by_origin: array<int, array{ran:int, answered:int, correct:int, promoted:int}>}}
     */
    public function score(TestRun $run): array
    {
        $rows = $run->dataset->scorableRows()->get();
        $items = $run->items()->with('results')->get()->keyBy('test_dataset_row_id');

        $authoritative = Consensus::computeAuthoritative(
            (array) ($run->mechanisms['enabled'] ?? ['vector', 'broker', 'direct']),
            (array) ($run->mechanisms['shadow'] ?? []),
        );
        $authCount = count($authoritative);

        $columns = array_fill_keys(
            [...array_keys(self::MECHANISM_COLUMNS), 'majority', 'overall'],
            ['ran' => 0, 'answered' => 0, 'correct' => 0],
        );

        // The funnel: for every non-cache-hit row, how many of the authoritative
        // mechanisms landed on the same heading (1..$authCount, "prevote"), and — for
        // the ones short of unanimity — whether the web search that resolved them was
        // confident+grounded enough to ACTUALLY write back into memory (wouldPromote*,
        // the real enabled/shadow gate, not a hypothetical one). See RunScorer's class
        // doc and AnswerCacheService::wouldPromote()/wouldPromoteGroundedSearch(). Each
        // bucket reuses tally()'s ['ran','answered','correct'] shape plus 'promoted'.
        $prevote = [];
        for ($n = 1; $n <= $authCount; $n++) {
            $prevote[$n] = ['ran' => 0, 'answered' => 0, 'correct' => 0, 'promoted' => 0];
        }
        $searchByOrigin = [];
        for ($n = 1; $n < $authCount; $n++) {
            $searchByOrigin[$n] = ['ran' => 0, 'answered' => 0, 'correct' => 0, 'promoted' => 0];
        }

        foreach ($rows as $row) {
            $item = $items->get($row->id);
            if ($item === null) {
                continue; // never classified (only if the run is still in flight)
            }
            $expHeading = $row->expected_heading;
            $expService = (bool) $row->expected_is_service;
            $byMech = $item->results->keyBy('mechanism');

            foreach (self::MECHANISM_COLUMNS as $col => $mech) {
                $r = $byMech->get($mech);
                if ($r === null) {
                    continue; // this mechanism did not run for this row
                }
                $this->tally($columns[$col], $r->matched_code, $r->kind, $expHeading, $expService);
            }

            // majority = pure consensus over the authoritative results, recomputed the
            // same way the runner did — independent of the later search flip.
            $authResults = $item->results->whereIn('mechanism', $authoritative)->values();
            if ($authResults->isNotEmpty()) {
                $c = $this->consensus->resolve($authResults);
                $this->tally($columns['majority'], $c['final_code'] ?? null, $c['kind'] ?? null, $expHeading, $expService);
            }

            // overall = the item's final answer after cache/consensus/search.
            $this->tally($columns['overall'], $item->final_code, $item->kind, $expHeading, $expService);

            // Funnel: skip rows with NO evidence at all (count === 0, e.g. no_match) —
            // folding them into the weakest agreement bucket would dilute its accuracy
            // with items that never carried any candidate in the first place.
            $ag = Consensus::agreementOf($authResults);
            if ($ag['count'] < 1) {
                continue;
            }

            $idx = min($ag['count'], $authCount);
            $this->tally($prevote[$idx], $ag['heading'], $ag['kind'], $expHeading, $expService);

            if ($idx === $authCount) {
                // Unanimous — this is exactly what Consensus::maybePromote() would have
                // fed into AnswerCacheService::promote() on the live path.
                if ($this->answerCache->wouldPromote($item, $ag)) {
                    $prevote[$idx]['promoted']++;
                }

                continue;
            }

            $search = $byMech->get('search');
            if ($search === null) {
                continue; // this tier hasn't reached the web search yet (run still in flight)
            }
            $this->tally($searchByOrigin[$idx], $search->matched_code, $search->kind, $expHeading, $expService);
            if ($this->answerCache->wouldPromoteGroundedSearch($item, $search, $authResults)) {
                $searchByOrigin[$idx]['promoted']++;
            }
        }

        $funnel = ['total' => $authCount, 'prevote' => $prevote, 'search_by_origin' => $searchByOrigin];

        return ['columns' => $columns, 'total' => $rows->count(), 'tokens' => $this->tokens($run), 'funnel' => $funnel];
    }

    /**
     * Total LLM tokens this run spent — summed from each mechanism result's stored usage
     * (attributable to the run; the shared product-brief and the web search are logged
     * separately in llm_usage, so this is a close lower bound on the true spend).
     */
    public function tokens(TestRun $run): int
    {
        return (int) DB::table('classification_results')
            ->join('classification_items', 'classification_items.id', '=', 'classification_results.classification_item_id')
            ->where('classification_items.test_run_id', $run->id)
            ->pluck('classification_results.usage')
            ->sum(fn ($u) => (int) (json_decode((string) $u, true)['total_tokens'] ?? 0));
    }

    /**
     * @param  array{ran:int, answered:int, correct:int}  $bucket
     */
    private function tally(array &$bucket, ?string $code, ?string $kind, ?string $expHeading, bool $expService): void
    {
        $bucket['ran']++;
        if ($code !== null && $code !== '') {
            $bucket['answered']++;
        }
        if (HeadingMatch::correct($code, $kind, $expHeading, $expService)) {
            $bucket['correct']++;
        }
    }
}
