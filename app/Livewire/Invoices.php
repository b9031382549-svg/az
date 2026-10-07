<?php

namespace App\Livewire;

use App\Models\EInvoice;
use App\Models\RubricatorNode;
use App\Services\Export\InvoiceLinesExporter;
use App\Services\Import\InvoiceUploads;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// The invoice rows — one row per invoice LINE (a legacy invoice-list row is a whole invoice).
// Line-level rows show their item, the code our classifier gave it and what the supplier declared.
// What the filters select can be downloaded as .xlsx with our answer per line (InvoiceExportController).
#[Layout('components.app-layout', ['title' => 'Invoices'])]
class Invoices extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $q = '';

    /** Selected upload (import batch key) or '' for all rows. */
    #[Url]
    public string $upload = '';

    /** One classification status (EInvoice::STATUSES) or '' for all. */
    #[Url]
    public string $status = '';

    public function updatingQ(): void
    {
        $this->resetPage();
    }

    public function updatingUpload(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
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
        $invoices = EInvoice::query()
            ->with('classificationItem.results') // results: isResolving() needs the search trace
            ->filtered($this->q, $this->upload, $this->status)
            // Undated lines (a list of names) go last — Postgres puts NULLs first in a desc sort.
            ->orderByRaw('invoice_date desc nulls last')
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

        // The download: one file, or — past one file's worth of lines — the parts to fetch.
        $filters = array_filter(['q' => trim($this->q), 'upload' => $this->upload, 'status' => $this->status]);

        return view('livewire.invoices', [
            'invoices' => $invoices,
            'headingNames' => $headingNames,
            'uploads' => app(InvoiceUploads::class)->recent(50),
            'exportFilters' => $filters,
            'exportParts' => InvoiceLinesExporter::parts($invoices->total()),
            'exportPartLines' => InvoiceLinesExporter::partLines(),
        ]);
    }
}
