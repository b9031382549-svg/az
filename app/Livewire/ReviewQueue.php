<?php

namespace App\Livewire;

use App\Models\Classification;
use App\Models\ImportBatch;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.app-layout', ['title' => 'Review queue'])]
class ReviewQueue extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'needs_review';

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

    public function confirm(int $id): void
    {
        Classification::whereKey($id)->update(['status' => 'confirmed']);
    }

    public function reject(int $id): void
    {
        Classification::whereKey($id)->update(['status' => 'rejected']);
    }

    public function render()
    {
        // Scope everything to the selected upload, so the list, the status tab
        // counts and the pagination all reflect one batch at a time.
        $scoped = fn () => Classification::query()
            ->when($this->batch !== 'all', fn ($q) => $q->where('batch', $this->batch));

        $items = $scoped()
            ->with('code')
            ->when($this->filter !== 'all', fn ($q) => $q->where('status', $this->filter))
            ->latest()
            ->paginate(15);

        $counts = $scoped()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $batches = $this->batchOptions();

        return view('livewire.review-queue', compact('items', 'counts', 'batches'));
    }

    /**
     * Recent uploads for the filter dropdown — derived from the classifications
     * themselves (so pre-existing batches still appear), labelled from
     * import_batches where a record exists.
     *
     * @return Collection<int, object>
     */
    private function batchOptions(): Collection
    {
        $rows = Classification::query()
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
}
