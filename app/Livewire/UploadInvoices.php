<?php

namespace App\Livewire;

use App\Models\EInvoice;
use App\Services\Import\InvoiceImporter;
use App\Support\Audit;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.app-layout', ['title' => 'Upload invoices'])]
class UploadInvoices extends Component
{
    use WithFileUploads;

    public $file;

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    public bool $skipDuplicates = false;

    public function updatedFile(): void
    {
        $this->reset('preview', 'report');
        $this->validate(['file' => 'required|file|max:25600']); // 25 MB

        $ext = strtolower((string) $this->file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $this->addError('file', __('Please upload a .xlsx, .xls or .csv file.'));
            $this->reset('file');

            return;
        }

        $this->preview = app(InvoiceImporter::class)->preview($this->file->getRealPath());
    }

    public function import(InvoiceImporter $importer): void
    {
        if (! $this->file || ! $this->preview || ! $this->preview['ok']) {
            return;
        }

        $filename = $this->file->getClientOriginalName();
        $this->report = $importer->import($this->file->getRealPath(), $this->skipDuplicates);

        Audit::log('invoice.import', [
            'file' => $filename,
            'skip_duplicates' => (bool) $this->skipDuplicates,
            'imported' => $this->report['imported'] ?? null,
            'skipped' => $this->report['skipped'] ?? null,
            'total' => $this->report['total'] ?? null,
            'error' => $this->report['error'] ?? null,
        ]);

        $this->reset('file', 'preview');
    }

    public function startOver(): void
    {
        $this->reset('file', 'preview', 'report', 'skipDuplicates');
    }

    public function deleteAll(InvoiceImporter $importer): void
    {
        $deleted = $importer->deleteAll();

        Audit::log('invoice.delete_all', ['deleted' => $deleted]);

        $this->reset('file', 'preview', 'report', 'skipDuplicates');
    }

    public function render()
    {
        $step = $this->report ? 3 : ($this->preview ? 2 : 1);

        return view('livewire.upload-invoices', [
            'step' => $step,
            'existing' => (int) EInvoice::count(),
        ]);
    }
}
