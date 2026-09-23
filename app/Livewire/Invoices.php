<?php

namespace App\Livewire;

use App\Models\EInvoice;
use App\Models\RubricatorNode;
use App\Services\Import\InvoiceUploads;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// The invoice rows — one row per invoice LINE (a legacy invoice-list row is a whole invoice).
// Line-level rows show their item, the code our classifier gave it and what the supplier declared.
#[Layout('components.app-layout', ['title' => 'Invoices'])]
class Invoices extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $q = '';

    /** Selected upload (import batch key) or '' for all rows. */
    #[Url]
    public string $upload = '';

    public function updatingQ(): void
    {
        $this->resetPage();
    }

    public function updatingUpload(): void
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
        // ILIKE on Postgres, LIKE on sqlite (tests) — both case-insensitive for the search.
        $likeOp = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $invoices = EInvoice::query()
            ->with('classificationItem.results') // results: isResolving() needs the search trace
            ->when($this->upload !== '', fn ($query) => $query->where('import_batch', $this->upload))
            ->when($term !== '', function ($query) use ($term, $likeOp) {
                $like = '%'.$term.'%';
                $query->where(fn ($w) => $w
                    ->where('supplier_tin', $likeOp, $like)
                    ->orWhere('recipient_tin', $likeOp, $like)
                    ->orWhere('number', $likeOp, $like)
                    ->orWhere('series', $likeOp, $like)
                    ->orWhere('item_name', $likeOp, $like)
                    ->orWhere('declared_code', $likeOp, $like)
                    ->orWhere('supplier_name', $likeOp, $like)
                    ->orWhere('recipient_name', $likeOp, $like));
            })
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(20);

        // Names of the 4-digit headings (or '99') the classifier answered with, for the tooltip.
        $headingCodes = $invoices->getCollection()
            ->map(fn (EInvoice $i) => $i->classificationItem?->final_code)
            ->filter()
            ->map(fn ($c) => mb_substr((string) $c, 0, 4))
            ->unique()->values();
        $headingNames = $headingCodes->isEmpty()
            ? collect()
            : RubricatorNode::whereIn('code', $headingCodes)->get(['code', 'title', 'title_en', 'title_ru'])
                ->mapWithKeys(fn ($n) => [(string) $n->code => $n->localizedTitle()]);

        return view('livewire.invoices', [
            'invoices' => $invoices,
            'headingNames' => $headingNames,
            'uploads' => app(InvoiceUploads::class)->recent(50),
        ]);
    }
}
