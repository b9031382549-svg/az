<?php

namespace App\Livewire;

use App\Models\Classification;
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

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
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
        $items = Classification::query()
            ->with('code')
            ->when($this->filter !== 'all', fn ($q) => $q->where('status', $this->filter))
            ->latest()
            ->paginate(15);

        $counts = Classification::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return view('livewire.review-queue', compact('items', 'counts'));
    }
}
