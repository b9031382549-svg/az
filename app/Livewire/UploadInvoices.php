<?php

namespace App\Livewire;

use App\Models\EInvoice;
use App\Services\Classify\BatchProgress;
use App\Services\Classify\BatchStats;
use App\Services\Import\InvoiceLinesImporter;
use App\Services\Import\InvoiceUploads;
use App\Support\Audit;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

// One upload for invoice files. The layout is recognised from the header: the line-level
// export ("Şablon") is imported line by line AND its item names are classified right away
// (with the same live progress as the Classify page); the legacy 15-column list is imported
// exactly as before.
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

    /**
     * The classification the last line-level upload started, for the live progress panel.
     *
     * @var array{batch:string, count:int, total:int, source:string, label:string}|null
     */
    public ?array $queued = null;

    public function updatedFile(): void
    {
        $this->reset('preview', 'report', 'queued');
        $this->validate(['file' => 'required|file|max:25600']); // 25 MB

        $ext = strtolower((string) $this->file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $this->addError('file', __('Please upload a .xlsx, .xls or .csv file.'));
            $this->reset('file');

            return;
        }

        // A line-level export of up to MAX_LINES reads in seconds — kept under the nginx
        // fastcgi_read_timeout of 120s.
        set_time_limit(110);

        $this->preview = app(InvoiceUploads::class)->preview($this->file->getRealPath());
    }

    public function import(InvoiceUploads $uploads): void
    {
        if (! $this->file || ! $this->preview || ! $this->preview['ok']) {
            return;
        }

        set_time_limit(110);

        $filename = (string) $this->file->getClientOriginalName();
        $this->report = $uploads->import($this->file->getRealPath(), $filename ?: 'Invoice upload', auth()->id(), $this->skipDuplicates);

        Audit::log('invoice.import', [
            'file' => $filename,
            'format' => $this->report['format'] ?? null,
            'batch' => $this->report['batch'] ?? null,
            'skip_duplicates' => (bool) $this->skipDuplicates,
            'imported' => $this->report['imported'] ?? null,
            'skipped' => $this->report['skipped'] ?? null,
            'items' => $this->report['items'] ?? null,
            'total' => $this->report['total'] ?? null,
            'error' => $this->report['error'] ?? null,
        ]);

        // A line-level upload is classified on import — hand off to the live progress panel.
        if (($this->report['items'] ?? 0) > 0) {
            $this->queued = [
                'batch' => (string) $this->report['batch'],
                'count' => (int) $this->report['items'],
                'total' => (int) $this->report['items'],
                'source' => 'invoices',
                'label' => $filename,
            ];
        }

        $this->reset('file', 'preview');
    }

    public function startOver(): void
    {
        $this->reset('file', 'preview', 'report', 'skipDuplicates', 'queued');
    }

    public function deleteAll(InvoiceUploads $uploads): void
    {
        $deleted = $uploads->deleteAll();

        Audit::log('invoice.delete_all', ['deleted' => $deleted]);

        $this->reset('file', 'preview', 'report', 'skipDuplicates', 'queued');
    }

    /** Delete one upload's invoice lines; its classification stays in Review and memory. */
    public function deleteUpload(string $key): void
    {
        $deleted = app(InvoiceUploads::class)->delete($key);

        Audit::log('invoice.delete_upload', ['batch' => $key, 'deleted' => $deleted]);
    }

    public function render()
    {
        $step = $this->report ? 3 : ($this->preview ? 2 : 1);

        $progress = null;
        $headingNames = collect();
        $batchStats = null;
        if ($this->queued) {
            $progress = app(BatchProgress::class)->for($this->queued['batch'], (int) $this->queued['count']);
            $headingNames = $progress['headingNames'];
            $batchStats = app(BatchStats::class)->for($this->queued['batch']);
        }

        return view('livewire.upload-invoices', [
            'step' => $step,
            'existing' => (int) EInvoice::count(),
            'uploads' => app(InvoiceUploads::class)->recent(),
            'maxLines' => InvoiceLinesImporter::MAX_LINES,
            'progress' => $progress,
            'headingNames' => $headingNames,
            'batchStats' => $batchStats,
        ]);
    }
}
