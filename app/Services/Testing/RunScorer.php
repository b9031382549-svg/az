<?php

namespace App\Services\Testing;

use App\Models\ClassificationItem;
use App\Models\ClassificationResult;
use App\Models\TestDatasetRow;
use App\Models\TestRun;
use App\Services\Classify\AnswerCacheService;
use App\Services\Classify\Consensus;
use App\Services\Classify\HeadingMatch;
use App\Services\Classify\TrashFilter;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Turns a finished run's stored classification_results into per-mechanism accuracy.
 *
 * Denominators FLOAT by design (we replicate the prod short path): a mechanism's
 * `ran` counts only the rows where it actually executed — memory runs on all rows,
 * vector/broker/direct only on rows memory and the trash step left, search only on
 * conflicts. To measure a mechanism over ALL rows, run with memory off (then every row
 * the trash step keeps reaches the mechanism stage). Everything is scored at the 4-digit
 * heading via the shared HeadingMatch.
 *
 * The trash step (rules + sorter, as in prod) takes a line out before the AI: a row with
 * a code that it removes is a miss in "overall", exactly as prod would leave it without a
 * code. A row that carries only a kind (TRASH / GOOD / SERVICE) runs the same pipeline;
 * "overall" asks only whether it ended as that kind, and the code columns ignore it.
 *
 * The SORTER is scored on its own, on the KIND of line (good / service / trash), over
 * every row that names one.
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
        private readonly TrashFilter $trash,
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
     * @return array{columns: array<string, array{ran:int, answered:int, correct:int}>, total:int, tokens:int, funnel: array{total:int, prevote: array<int, array{ran:int, answered:int, correct:int, promoted:int}>, search_by_origin: array<int, array{ran:int, answered:int, correct:int, promoted:int}>}, sorter: array{threshold: ?float, confusion: array<string, array<string, int>>, with_rules: array{tp:int, fp:int, fn:int}}, trash: array{removed:int, right:int, by_rules:int, by_sorter:int, trash_rows:int}}
     */
    public function score(TestRun $run): array
    {
        $rows = $run->dataset->scorableRows()->get();
        $items = $run->items()->with('results')->get()->keyBy('test_dataset_row_id');

        $authoritative = Consensus::computeAuthoritative(
            (array) ($run->mechanisms['enabled'] ?? ['vector', 'direct']),
            (array) ($run->mechanisms['shadow'] ?? []),
        );
        $authCount = count($authoritative);

        $columns = array_fill_keys(
            [...array_keys(self::MECHANISM_COLUMNS), 'majority', 'overall', 'sorter'],
            ['ran' => 0, 'answered' => 0, 'correct' => 0],
        );
        $sorter = ['threshold' => null, 'confusion' => [], 'with_rules' => ['tp' => 0, 'fp' => 0, 'fn' => 0]];
        // What the trash step (rules + sorter) took out before the AI, and how many of those
        // rows really are trash; `trash_rows` = every row whose expected kind is trash.
        $trashStep = ['removed' => 0, 'right' => 0, 'by_rules' => 0, 'by_sorter' => 0, 'trash_rows' => 0];

        // The funnel: for every non-cache-hit row, how many of the authoritative
        // mechanisms landed on the same heading (1..$authCount, "prevote"), and whether
        // that answer (unanimous) or the web search that resolved a short-of-unanimous
        // conflict was confident+grounded enough to ACTUALLY write back into memory
        // (wouldPromote/wouldPromoteGroundedSearch — the real enabled/shadow gate, not a
        // hypothetical one; neither is gated on test_run_id, so both fire for real during
        // a test run exactly like prod — see AnswerCacheService::memoryScope() and
        // TestRunFinalizer). Each bucket reuses tally()'s ['ran','answered','correct']
        // shape plus 'promoted'.
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

            $this->scoreSorter($columns['sorter'], $sorter, $row, $byMech->get('sorter'));
            $this->scoreTrashStep($trashStep, $row, $item);
            if (! $row->hasExpectedCode()) {
                // A kind-only row (TRASH / GOOD / SERVICE): it ran the whole pipeline; all
                // "overall" asks is whether it ended as that kind.
                $this->tallyKind($columns['overall'], $item, (string) $row->expected_type);

                continue;
            }

            foreach (self::MECHANISM_COLUMNS as $col => $mech) {
                $r = $byMech->get($mech);
                if ($r === null) {
                    continue; // this mechanism did not run for this row
                }
                // The vector no longer commits a single pick — its answer is the ranked
                // shortlist. Score it as MEMBERSHIP (recall@K): correct when the expected
                // answer sits in its top-K candidates, matching how consensus consumes it.
                if ($mech === 'vector') {
                    $columns[$col]['ran']++;
                    if (! empty((array) $r->candidates)) {
                        $columns[$col]['answered']++;
                    }
                    $target = $expService ? '99' : $expHeading;
                    if ($target !== null && Consensus::vectorContains($r, $target, $expService ? 'service' : null)) {
                        $columns[$col]['correct']++;
                    }

                    continue;
                }
                $this->tally($columns[$col], $r->matched_code, $r->kind, $expHeading, $expService);
            }

            // majority = pure consensus over the authoritative results, recomputed the
            // same way the runner did — independent of the later search flip.
            $authResults = $item->results->whereIn('mechanism', $authoritative)->values();
            if ($authResults->isNotEmpty()) {
                $c = $this->consensus->resolve($authResults, $item->results->firstWhere('mechanism', 'sorter'));
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

            // Promotion is item-level now (resolution === 'agreed' via broker == direct +
            // vector top-K), so it can land in ANY prevote bucket — vector's raw top-1 need
            // not match the winning heading. Count it wherever the item bucketed.
            if ($this->answerCache->wouldPromote($item)) {
                $prevote[$idx]['promoted']++;
            }

            if ($idx === $authCount) {
                continue; // fully unanimous on matched_code → never went to search
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

        return ['columns' => $columns, 'total' => $rows->count(), 'tokens' => $this->tokens($run), 'funnel' => $funnel, 'sorter' => $sorter, 'trash' => $trashStep];
    }

    /** The kind a run's item ended as: trash, a service, a good — or null (no answer). */
    public static function endedAs(ClassificationItem $item): ?string
    {
        if ($item->resolution === 'trash') {
            return 'trash';
        }
        if ((string) $item->final_code === '') {
            return null;
        }

        return HeadingMatch::isService($item->kind, $item->final_code) ? 'service' : 'good';
    }

    /**
     * @param  array{ran:int, answered:int, correct:int}  $bucket
     */
    private function tallyKind(array &$bucket, ClassificationItem $item, string $expected): void
    {
        $ended = self::endedAs($item);
        $bucket['ran']++;
        $bucket['answered'] += $ended !== null ? 1 : 0;
        $bucket['correct'] += $ended === $expected ? 1 : 0;
    }

    /**
     * @param  array{removed:int, right:int, by_rules:int, by_sorter:int, trash_rows:int}  $step
     */
    private function scoreTrashStep(array &$step, TestDatasetRow $row, ClassificationItem $item): void
    {
        $isTrash = $row->expected_type === 'trash';
        $step['trash_rows'] += $isTrash ? 1 : 0;
        if ($item->resolution !== 'trash') {
            return;
        }
        $step['removed']++;
        $step['right'] += $isTrash ? 1 : 0;
        $rule = data_get($item->results->firstWhere('mechanism', 'trash')?->trace, 'rule');
        $step[$rule === 'sorter' ? 'by_sorter' : 'by_rules']++;
    }

    /**
     * The sorter's verdict vs the row's kind, scored on what it ACTS on in prod: trash at the
     * threshold stored with its verdict, otherwise its top class. A top "trash" below the
     * threshold acts on nothing — 'unsure' (ran, not answered). with_rules = the whole trash
     * step as prod runs it: a TrashFilter rule fires, or the sorter is sure.
     *
     * @param  array{ran:int, answered:int, correct:int}  $column
     * @param  array{threshold: ?float, confusion: array<string, array<string, int>>, with_rules: array{tp:int, fp:int, fn:int}}  $stats
     */
    private function scoreSorter(array &$column, array &$stats, TestDatasetRow $row, ?ClassificationResult $verdict): void
    {
        $expected = $row->expected_type;
        if ($expected === null || $verdict === null) {
            return;
        }

        $threshold = (float) data_get($verdict->trace, 'trash_threshold', config('classify.sorter.trash_threshold'));
        $sure = (float) data_get($verdict->trace, 'probs.trash', 0.0) >= $threshold;
        $predicted = $sure ? 'trash' : ($verdict->kind === 'trash' ? 'unsure' : (string) $verdict->kind);

        $column['ran']++;
        $column['answered'] += $predicted === 'unsure' ? 0 : 1;
        $column['correct'] += $predicted === $expected ? 1 : 0;
        $stats['threshold'] = $threshold;
        $stats['confusion'][$expected][$predicted] = ($stats['confusion'][$expected][$predicted] ?? 0) + 1;

        $flagged = $sure || $this->trash->reason((string) $row->source_text) !== null;
        $key = match (true) {
            $flagged && $expected === 'trash' => 'tp',
            $flagged => 'fp',
            $expected === 'trash' => 'fn',
            default => null,
        };
        if ($key !== null) {
            $stats['with_rules'][$key]++;
        }
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
