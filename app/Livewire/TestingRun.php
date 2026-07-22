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
        $done = $this->run->items()->where('resolution', '!=', 'pending')->count();
        $complete = $this->run->status === 'done';

        $rowsPage = $this->run->dataset->scorableRows()->orderBy('id')->paginate(25);
        $detail = $complete ? $this->detail($rowsPage->items()) : [];

        // Duration: final when done, else elapsed so far. Tokens: the persisted total
        // when scored, else a live sum of what's been spent so far.
        $end = $this->run->finished_at ?? now();
        $durationSeconds = $this->run->started_at ? $end->diffInSeconds($this->run->started_at) : null;
        $tokens = $this->run->accuracy['tokens'] ?? app(RunScorer::class)->tokens($this->run);

        return view('livewire.testing-run', [
            'total' => $total,
            'done' => $done,
            'complete' => $complete,
            'pct' => $total > 0 ? (int) round(min(100, $done / $total * 100)) : 0,
            'accuracy' => $this->run->accuracy['columns'] ?? [],
            'durationSeconds' => $durationSeconds,
            'tokens' => (int) $tokens,
            'rowsPage' => $rowsPage,
            'detail' => $detail,
            'majorityLabel' => $this->majorityLabel(),
        ]);
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

    /** e.g. "majority 2/3" — the threshold N over M authoritative mechanisms. */
    private function majorityLabel(): string
    {
        $auth = Consensus::computeAuthoritative(
            (array) ($this->run->mechanisms['enabled'] ?? []),
            (array) ($this->run->mechanisms['shadow'] ?? []),
        );
        $m = count($auth);
        $n = intdiv($m, 2) + 1;

        return __('majority :n/:m', ['n' => $n, 'm' => $m]);
    }
}
