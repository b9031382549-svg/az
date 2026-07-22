<?php

namespace App\Services\Testing;

use App\Models\TestRun;
use App\Services\Classify\Consensus;
use App\Services\Classify\HeadingMatch;

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

    public function __construct(private readonly Consensus $consensus) {}

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

    /** Every item has a terminal resolution AND no conflict is still awaiting its search. */
    private function isSettled(TestRun $run): bool
    {
        if ($run->items()->where('resolution', 'pending')->exists()) {
            return false;
        }

        // A conflict that claimed a search (search_resolved_at set) but has no 'search'
        // result row yet is mid-search — wait for it before scoring the search/overall.
        return ! $run->items()
            ->where('resolution', 'conflict')
            ->whereNotNull('search_resolved_at')
            ->whereDoesntHave('results', fn ($q) => $q->where('mechanism', 'search'))
            ->exists();
    }

    /**
     * @return array{columns: array<string, array{ran:int, answered:int, correct:int}>, total:int}
     */
    public function score(TestRun $run): array
    {
        $rows = $run->dataset->scorableRows()->get();
        $items = $run->items()->with('results')->get()->keyBy('test_dataset_row_id');

        $authoritative = Consensus::computeAuthoritative(
            (array) ($run->mechanisms['enabled'] ?? ['vector', 'broker', 'direct']),
            (array) ($run->mechanisms['shadow'] ?? []),
        );

        $columns = array_fill_keys(
            [...array_keys(self::MECHANISM_COLUMNS), 'majority', 'overall'],
            ['ran' => 0, 'answered' => 0, 'correct' => 0],
        );

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
        }

        return ['columns' => $columns, 'total' => $rows->count()];
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
