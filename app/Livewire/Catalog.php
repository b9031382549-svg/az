<?php

namespace App\Livewire;

use App\Models\CatalogCode;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.app-layout', ['title' => 'Catalog'])]
class Catalog extends Component
{
    use WithPagination;

    #[Url]
    public string $q = '';

    #[Url]
    public string $kind = 'all';

    public function updatingQ(): void
    {
        $this->resetPage();
    }

    public function updatingKind(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $term = trim($this->q);

        $rows = CatalogCode::query()
            ->when($this->kind !== 'all', fn ($q) => $q->where('kind', $this->kind))
            ->when($term !== '', function ($q) use ($term) {
                if (preg_match('/^\d+$/', $term)) {
                    $q->where('code', 'like', $term.'%');
                } else {
                    $q->where('name', 'ilike', '%'.$term.'%');
                }
            })
            ->orderBy('code')
            ->paginate(25);

        return view('livewire.catalog', compact('rows'));
    }
}
