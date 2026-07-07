<?php

namespace App\Livewire;

use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Models\ImportBatch;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.app-layout', ['title' => 'Review queue'])]
class ReviewQueue extends Component
{
    use WithPagination;

    /** Resolutions that still need a human ("open"). */
    private const OPEN = ['review', 'conflict', 'blocked_on_fact'];

    /** Resolutions a bulk reject may target (not yet human-decided, not no_match). */
    private const ACTIONABLE = ['agreed', 'review', 'conflict', 'blocked_on_fact'];

    /** Resolution display metadata for the report donut + legend. */
    private const RESOLUTION_META = [
        'agreed' => ['label' => 'Agreed', 'color' => '#3f6b4f'],
        'ai_resolved' => ['label' => 'AI resolved', 'color' => '#3a6ea5'],
        'confirmed' => ['label' => 'Confirmed', 'color' => '#5b8568'],
        'review' => ['label' => 'Review', 'color' => '#c2872b'],
        'conflict' => ['label' => 'Conflict', 'color' => '#B5462E'],
        'blocked_on_fact' => ['label' => 'Blocked (fact)', 'color' => '#7c5cbf'],
        'no_match' => ['label' => 'No match', 'color' => '#9a9183'],
        'rejected' => ['label' => 'Rejected', 'color' => '#8a8175'],
    ];

    #[Url]
    public string $filter = 'open';

    /** Selected upload (batch key), or "all". */
    #[Url]
    public string $batch = 'all';

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
    }

    public function updatedBatch(): void
    {
        $this->resetPage();
    }

    /**
     * Confirm an item with the chosen code — keep the agreed pick or (for a
     * conflict) pick which mechanism's answer is right. Only codes some mechanism
     * actually considered for THIS item are allowed.
     */
    public function confirmWith(int $id, string $code): void
    {
        $item = ClassificationItem::with('results')->find($id);
        if (! $item) {
            return;
        }

        if (! in_array($code, $item->allowedCodes(), true)) {
            return;
        }

        $cand = CatalogCode::where('code', $code)->first();
        if (! $cand) {
            return;
        }

        $was = $item->final_code;
        $item->update([
            'final_code' => $cand->code,
            'final_catalog_id' => $cand->id,
            'kind' => $cand->kind, // authoritative (99 => service)
            'resolution' => 'confirmed',
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now(),
        ]);

        Audit::log(
            ((string) $was !== $code) ? 'classification.corrected' : 'classification.confirm',
            ['id' => $id, 'code' => $code, 'was' => $was],
            $item,
        );
    }

    public function reject(int $id): void
    {
        ClassificationItem::whereKey($id)->update(['resolution' => 'rejected']);
        Audit::log('classification.reject', ['id' => $id]);
    }

    /** Confirm every item in the selected upload that already has an agreed code. */
    public function confirmAll(): void
    {
        if ($this->batch === 'all') {
            return;
        }

        $updated = ClassificationItem::where('batch', $this->batch)
            ->whereIn('resolution', ['agreed', 'review'])
            ->update([
                'resolution' => 'confirmed',
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
            ]);

        Audit::log('batch.bulk_confirmed', ['batch' => $this->batch, 'updated' => $updated]);
        $this->resetPage();
    }

    /** Reject every still-actionable item in the selected upload. */
    public function rejectAll(): void
    {
        if ($this->batch === 'all') {
            return;
        }

        $updated = ClassificationItem::where('batch', $this->batch)
            ->whereIn('resolution', self::ACTIONABLE)
            ->update(['resolution' => 'rejected']);

        Audit::log('batch.bulk_rejected', ['batch' => $this->batch, 'updated' => $updated]);
        $this->resetPage();
    }

    /** Delete the selected upload entirely (its items + results + batch record). */
    public function deleteBatch(): void
    {
        if ($this->batch === 'all') {
            return;
        }

        $deleted = ClassificationItem::where('batch', $this->batch)->count();
        ClassificationItem::where('batch', $this->batch)->delete(); // cascades to results
        ImportBatch::where('key', $this->batch)->delete();
        Audit::log('batch.delete', ['batch' => $this->batch, 'deleted' => $deleted]);

        $this->batch = 'all';
        $this->resetPage();
    }

    public function render()
    {
        $scoped = fn () => ClassificationItem::query()
            ->when($this->batch !== 'all', fn ($q) => $q->where('batch', $this->batch));

        $items = $scoped()
            ->with(['finalCode', 'translation', 'results', 'adjudications'])
            ->when($this->filter === 'open', fn ($q) => $q->whereIn('resolution', self::OPEN))
            ->when(! in_array($this->filter, ['all', 'open'], true), fn ($q) => $q->where('resolution', $this->filter))
            ->latest()
            ->paginate(15);

        $counts = $scoped()
            ->selectRaw('resolution, count(*) as c')
            ->groupBy('resolution')
            ->pluck('c', 'resolution');

        // Localized catalog names for the candidate dropdowns.
        $codes = $items->getCollection()
            ->flatMap(fn ($item) => $item->results
                ->flatMap(fn ($r) => collect($r->candidates ?? [])->pluck('code')->push($r->matched_code))
                ->push($item->final_code)
                ->push($item->adjudications->sortByDesc('id')->first()?->winning_code))
            ->filter()
            ->map(fn ($c) => (string) $c)
            ->unique()
            ->values();

        $catalogNames = CatalogCode::query()
            ->whereIn('code', $codes)
            ->get(['code', 'name', 'name_en', 'name_ru'])
            ->mapWithKeys(fn ($c) => [(string) $c->code => $c->localizedName()]);

        $openCount = collect(self::OPEN)->sum(fn ($r) => (int) ($counts[$r] ?? 0));

        return view('livewire.review-queue', [
            'items' => $items,
            'counts' => $counts,
            'openCount' => $openCount,
            'batches' => $this->batchOptions(),
            'report' => $this->report($scoped, $counts),
            'actionableCount' => collect(self::ACTIONABLE)->sum(fn ($r) => (int) ($counts[$r] ?? 0)),
            'catalogNames' => $catalogNames,
        ]);
    }

    /**
     * Recent uploads for the filter dropdown — derived from the items themselves
     * (so pre-existing batches still appear), labelled from import_batches.
     *
     * @return Collection<int, object>
     */
    private function batchOptions(): Collection
    {
        $rows = ClassificationItem::query()
            ->whereNotNull('batch')
            ->selectRaw('batch, count(*) as total, max(created_at) as last_at')
            ->groupBy('batch')
            ->orderByRaw('max(created_at) desc')
            ->limit(50)
            ->get();

        $labels = ImportBatch::whereIn('key', $rows->pluck('batch'))->pluck('label', 'key');

        return $rows->map(fn ($r) => (object) [
            'key' => $r->batch,
            'label' => $labels[$r->batch] ?? 'Earlier import',
            'total' => (int) $r->total,
            'last_at' => $r->last_at,
        ]);
    }

    /**
     * Distribution report for the current scope: resolution donut, good/service
     * split, consensus breakdown and the top HS chapters.
     *
     * @param  callable():Builder  $scoped
     * @param  Collection<string, int>  $counts
     * @return array<string, mixed>
     */
    private function report(callable $scoped, Collection $counts): array
    {
        $total = (int) $counts->sum();

        $r = 54.0;
        $circ = 2 * M_PI * $r;
        $segments = [];
        $cumulative = 0.0;
        foreach (self::RESOLUTION_META as $key => $meta) {
            $c = (int) ($counts[$key] ?? 0);
            if ($c === 0) {
                continue;
            }
            $len = $total > 0 ? $c / $total * $circ : 0;
            $segments[] = [
                'key' => $key,
                'color' => $meta['color'],
                'label' => $meta['label'],
                'count' => $c,
                'pct' => $total > 0 ? round($c / $total * 100) : 0,
                'len' => $len,
                'gap' => $circ - $len,
                'offset' => -$cumulative,
            ];
            $cumulative += $len;
        }

        $kind = $scoped()->selectRaw('kind, count(*) as c')->groupBy('kind')->pluck('c', 'kind');

        $chapters = $scoped()
            ->whereNotNull('final_code')
            ->selectRaw('substr(final_code, 1, 2) as chapter, count(*) as c')
            ->groupBy('chapter')
            ->orderByDesc('c')
            ->limit(8)
            ->get();

        return [
            'total' => $total,
            'donut' => ['r' => $r, 'circ' => $circ, 'segments' => $segments],
            'good' => (int) ($kind['good'] ?? 0),
            'service' => (int) ($kind['service'] ?? 0),
            'consensus' => [
                'agreed' => (int) ($counts['agreed'] ?? 0),
                'ai_resolved' => (int) ($counts['ai_resolved'] ?? 0),
                'review' => (int) ($counts['review'] ?? 0),
                'conflict' => (int) ($counts['conflict'] ?? 0),
            ],
            'chapters' => $chapters,
        ];
    }
}
