<?php

namespace App\Livewire;

use App\Models\TestDatasetRow;
use App\Models\TestRun;
use App\Services\Classify\Consensus;
use App\Services\Classify\HeadingMatch;
use App\Services\Testing\RunScorer;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

// One run: live progress while classifying, then per-mechanism accuracy + a per-row
// detail table (each mechanism's heading, correct or not, linked to the trace page).
#[Layout('components.app-layout', ['title' => 'Run'])]
class TestingRun extends Component
{
    use WithPagination;

    /** Column order shown in both the accuracy table and the per-row detail. */
    public const COLUMNS = ['memory', 'vector', 'broker', 'direct', 'majority', 'search', 'overall'];

    public TestRun $run;

    public function mount(TestRun $run): void
    {
        $this->run = $run;
    }

    public function render()
    {
        $this->run->refresh();
        $total = (int) $this->run->total;
        // done = fully classified rows, INCLUDING the search tie-break (not just the
        // vector/broker/direct votes) — so the bar hits 100% only when the run settles.
        $done = app(RunScorer::class)->doneCount($this->run);
        $complete = $this->run->status === 'done';

        $rowsPage = $this->run->dataset->scorableRows()->orderBy('id')->paginate(25);
        $detail = $complete ? $this->detail($rowsPage->items()) : [];

        // Duration: final when done, else elapsed so far. Tokens: the persisted total
        // when scored, else a live sum of what's been spent so far.
        $end = $this->run->finished_at ?? now();
        // abs(): Carbon 3's diffInSeconds is signed, so guard the direction.
        $durationSeconds = $this->run->started_at ? (int) abs($end->diffInSeconds($this->run->started_at)) : null;
        $tokens = $this->run->accuracy['tokens'] ?? app(RunScorer::class)->tokens($this->run);
        $accuracy = $this->run->accuracy['columns'] ?? [];

        return view('livewire.testing-run', [
            'total' => $total,
            'done' => $done,
            'complete' => $complete,
            'pct' => $total > 0 ? (int) round(min(100, $done / $total * 100)) : 0,
            'accuracy' => $accuracy,
            'funnelRows' => $this->funnelRows($this->run->accuracy['funnel'] ?? null, $accuracy),
            'durationSeconds' => $durationSeconds,
            'tokens' => (int) $tokens,
            'rowsPage' => $rowsPage,
            'detail' => $detail,
        ]);
    }

    /**
     * Flatten the run's funnel breakdown into ordered display rows for the top accuracy
     * table: Step 1 (memory), Step 2 (vector/broker/direct + how many of them agreed),
     * Step 3 (web search overall + which agreement tier its confident+grounded answers
     * came from). Null when the run predates the funnel breakdown, so the view falls
     * back to the flat per-mechanism table.
     *
     * @param  array{total:int, prevote: array<int, array{ran:int, correct:int, promoted:int}>, search_by_origin: array<int, array{ran:int, correct:int, promoted:int}>}|null  $funnel
     * @param  array<string, array{ran:int, correct:int}>  $accuracy
     * @return array<int, array{step:string, label:string, bucket: array{ran:int, correct:int}|null, promoted:?int}>|null
     */
    private function funnelRows(?array $funnel, array $accuracy): ?array
    {
        if ($funnel === null) {
            return null;
        }

        $rows = [
            ['step' => '1', 'label' => __('Memory'), 'bucket' => $accuracy['memory'] ?? null, 'promoted' => null],
        ];

        foreach (['vector' => __('Vector'), 'broker' => __('Broker'), 'direct' => __('Direct')] as $col => $label) {
            $rows[] = ['step' => '2', 'label' => $label, 'bucket' => $accuracy[$col] ?? null, 'promoted' => null];
        }

        $total = (int) $funnel['total'];
        foreach ($funnel['prevote'] as $n => $bucket) {
            $rows[] = [
                'step' => '2',
                'label' => __('Match :n/:m', ['n' => $n, 'm' => $total]),
                'bucket' => $bucket,
                'promoted' => $n === $total ? (int) $bucket['promoted'] : null,
            ];
        }

        $rows[] = ['step' => '3', 'label' => __('Web search'), 'bucket' => $accuracy['search'] ?? null, 'promoted' => null];
        foreach ($funnel['search_by_origin'] as $n => $bucket) {
            $rows[] = [
                'step' => '3',
                'label' => __('Search confirms :n/:m', ['n' => $n, 'm' => $total]),
                'bucket' => $bucket,
                'promoted' => (int) $bucket['promoted'],
            ];
        }

        return $rows;
    }

    /**
     * Per-row cells for the visible page: each mechanism's heading + hit/miss,
     * recomputed from the stored results (majority via the same Consensus::resolve
     * the runner used).
     *
     * @param  array<int, TestDatasetRow>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function detail(array $rows): array
    {
        $rowIds = collect($rows)->pluck('id');
        $items = $this->run->items()
            ->whereIn('test_dataset_row_id', $rowIds)
            ->with('results')
            ->get()
            ->keyBy('test_dataset_row_id');

        $authoritative = Consensus::computeAuthoritative(
            (array) ($this->run->mechanisms['enabled'] ?? []),
            (array) ($this->run->mechanisms['shadow'] ?? []),
        );
        $consensus = app(Consensus::class);

        return collect($rows)->map(function (TestDatasetRow $row) use ($items, $authoritative, $consensus) {
            $item = $items->get($row->id);
            $byMech = $item ? $item->results->keyBy('mechanism') : collect();

            $cells = [];
            foreach (['memory' => 'cache', 'vector' => 'vector', 'broker' => 'broker', 'direct' => 'direct', 'search' => 'search'] as $col => $mech) {
                $r = $byMech->get($mech);
                $cells[$col] = $r ? $this->cell($r->matched_code, $r->kind, $row) : null;
            }

            $authResults = $item ? $item->results->whereIn('mechanism', $authoritative)->values() : collect();
            if ($authResults->isNotEmpty()) {
                $c = $consensus->resolve($authResults);
                $cells['majority'] = $this->cell($c['final_code'] ?? null, $c['kind'] ?? null, $row);
            } else {
                $cells['majority'] = null;
            }

            $cells['overall'] = $item ? $this->cell($item->final_code, $item->kind, $row) : null;

            return [
                'name' => $row->source_text,
                'expected' => $row->expected_is_service ? 'SVC' : $row->expected_heading,
                'item_id' => $item?->id,
                'cells' => $cells,
            ];
        })->all();
    }

    /** @return array{heading:string, ok:bool} */
    private function cell(?string $code, ?string $kind, TestDatasetRow $row): array
    {
        return [
            'heading' => HeadingMatch::isService($kind, $code) ? 'SVC' : (HeadingMatch::heading($code) ?? '—'),
            'ok' => HeadingMatch::correct($code, $kind, $row->expected_heading, (bool) $row->expected_is_service),
        ];
    }
}
