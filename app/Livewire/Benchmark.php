<?php

namespace App\Livewire;

use App\Models\CatalogCode;
use App\Services\Classify\BenchmarkService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * How well our classifier hits the two external reference ("gold") sets. Match is
 * by name; each reference is scored at its own granularity (Ivan full code, Fedor
 * 4-digit heading + good/service). Read-only — no LLM, no writes.
 */
#[Layout('components.app-layout', ['title' => 'Benchmark'])]
class Benchmark extends Component
{
    use WithPagination;

    #[Url]
    public string $source = 'all';   // all | ivan | fedor

    #[Url]
    public string $status = 'disagree'; // all | agree | disagree | no_code | unclassified

    private const PER_PAGE = 25;

    public function setSource(string $s): void
    {
        $this->source = in_array($s, ['ivan', 'fedor'], true) ? $s : 'all';
        $this->resetPage();
    }

    public function setStatus(string $s): void
    {
        $this->status = in_array($s, ['agree', 'disagree', 'no_code', 'no_ref', 'unclassified'], true) ? $s : 'all';
        $this->resetPage();
    }

    public function render(BenchmarkService $benchmark)
    {
        $score = $benchmark->score();

        $rows = $score['rows']
            ->when($this->source !== 'all', fn ($r) => $r->where('source', $this->source))
            ->when($this->status !== 'all', fn ($r) => $r->where('status', $this->status))
            ->values();

        $page = $this->paginate($rows);

        // Localized catalog names for the codes shown on THIS page only.
        $codes = collect($page->items())
            ->flatMap(fn ($r) => [$r['our_code'], $r['gold_code']])
            ->filter()->map(fn ($c) => (string) $c)->unique()->values();
        $catalogNames = CatalogCode::query()->whereIn('code', $codes)
            ->get(['code', 'name', 'name_en', 'name_ru'])
            ->mapWithKeys(fn ($c) => [(string) $c->code => $c->localizedName()]);

        return view('livewire.benchmark', [
            'sources' => $score['sources'],
            'overlap' => $score['overlap'],
            'page' => $page,
            'catalogNames' => $catalogNames,
        ]);
    }

    /**
     * Paginate an already-computed collection (the comparison is done in PHP, so
     * there is no query to paginate).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function paginate($rows): LengthAwarePaginator
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
