<?php

namespace App\Livewire;

use App\Models\EInvoice;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.app-layout', ['title' => 'Invoices'])]
class Invoices extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $q = '';

    public function updatingQ(): void
    {
        $this->resetPage();
    }

    public function clear(): void
    {
        $this->q = '';
        $this->resetPage();
    }

    public function render()
    {
        $term = trim($this->q);

        $invoices = EInvoice::query()
            ->when($term !== '', function ($query) use ($term) {
                $like = '%'.$term.'%';
                $query->where(fn ($w) => $w
                    ->where('supplier_tin', 'ilike', $like)
                    ->orWhere('recipient_tin', 'ilike', $like)
                    ->orWhere('number', 'ilike', $like)
                    ->orWhere('series', 'ilike', $like));
            })
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.invoices', compact('invoices'));
    }
}
