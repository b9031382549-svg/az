<?php

namespace App\Livewire;

use App\Models\TestRun;
use App\Services\Classify\HeadingMatch;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

// Before/after: two runs of the same dataset side by side — per-mechanism accuracy
// deltas and the per-row diff (which rows the "overall" answer flipped on).
#[Layout('components.app-layout', ['title' => 'Compare runs'])]
class TestingCompare extends Component
{
    #[Url]
    public ?int $a = null;

    #[Url]
    public ?int $b = null;

    private const PER_PAGE = 25;

    public function render()
    {
        $runA = $this->a ? TestRun::find($this->a) : null;
        $runB = $this->b ? TestRun::find($this->b) : null;

        $ready = $runA && $runB && $runA->test_dataset_id === $runB->test_dataset_id;

        $columns = TestingRun::COLUMNS;
        $deltas = $ready ? $this->deltas($runA, $runB, $columns) : [];
        $flips = $ready ? $this->flips($runA, $runB) : collect();
        $page = $this->paginate($flips);

        return view('livewire.testing-compare', [
            'runA' => $runA,
            'runB' => $runB,
            'ready' => $ready,
            'mismatch' => $runA && $runB && $runA->test_dataset_id !== $runB->test_dataset_id,
            'columns' => $columns,
            'deltas' => $deltas,
            'page' => $page,
            'flipTotal' => $flips->count(),
        ]);
    }

    /**
     * Per-column accuracy for both runs + the delta (percentage points).
     *
     * @param  array<int, string>  $columns
     * @return array<string, array{a:?float, b:?float, delta:?float, aRan:int, bRan:int}>
     */
    private function deltas(TestRun $runA, TestRun $runB, array $columns): array
    {
        $accA = $runA->accuracy['columns'] ?? [];
        $accB = $runB->accuracy['columns'] ?? [];
        $out = [];
        foreach ($columns as $c) {
            $a = $this->pct($accA[$c] ?? null);
            $b = $this->pct($accB[$c] ?? null);
            $out[$c] = [
                'a' => $a,
                'b' => $b,
                'delta' => ($a !== null && $b !== null) ? round($b - $a, 1) : null,
                'aRan' => (int) ($accA[$c]['ran'] ?? 0),
                'bRan' => (int) ($accB[$c]['ran'] ?? 0),
            ];
        }

        return $out;
    }

    /** @param array{ran:int, correct:int}|null $bucket */
    private function pct(?array $bucket): ?float
    {
        $ran = (int) ($bucket['ran'] ?? 0);

        return $ran > 0 ? round(100 * (int) ($bucket['correct'] ?? 0) / $ran, 1) : null;
    }

    /**
     * Rows whose OVERALL answer changed between the two runs (heading or hit/miss).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function flips(TestRun $runA, TestRun $runB): Collection
    {
        $rows = $runA->dataset->scorableRows()->orderBy('id')->get();
        $itemsA = $runA->items()->get()->keyBy('test_dataset_row_id');
        $itemsB = $runB->items()->get()->keyBy('test_dataset_row_id');

        return $rows->map(function ($row) use ($itemsA, $itemsB) {
            $ia = $itemsA->get($row->id);
            $ib = $itemsB->get($row->id);
            $ha = $this->overallHeading($ia);
            $hb = $this->overallHeading($ib);
            $okA = $ia && HeadingMatch::correct($ia->final_code, $ia->kind, $row->expected_heading, (bool) $row->expected_is_service);
            $okB = $ib && HeadingMatch::correct($ib->final_code, $ib->kind, $row->expected_heading, (bool) $row->expected_is_service);

            return [
                'name' => $row->source_text,
                'expected' => $row->expected_is_service ? 'SVC' : $row->expected_heading,
                'a' => $ha, 'okA' => $okA,
                'b' => $hb, 'okB' => $okB,
                'changed' => $ha !== $hb || $okA !== $okB,
            ];
        })->filter(fn ($r) => $r['changed'])->values();
    }

    private function overallHeading($item): string
    {
        if ($item === null) {
            return '—';
        }

        return HeadingMatch::isService($item->kind, $item->final_code)
            ? 'SVC'
            : (HeadingMatch::heading($item->final_code) ?? '—');
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function paginate(Collection $rows): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage() ?: 1;

        return new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }
}
