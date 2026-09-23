<?php

namespace App\Livewire;

use App\Models\EInvoice;
use App\Models\ImportBatch;
use App\Services\Classify\BatchProgress;
use App\Services\Classify\BatchStats;
use App\Services\Import\BackgroundInvoiceUploads;
use App\Services\Import\InvoiceUploads;
use App\Services\Import\SheetStream;
use App\Support\Audit;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

// One upload for invoice files. The layout is recognised from the header: the line-level
// export ("Şablon") is imported line by line AND its item names are classified right away
// (with the same live progress as the Classify page); the legacy 15-column list is imported
// exactly as before. Small files are handled inside the request; a big .xlsx/.csv goes through
// the same steps — preview, then Import — in the background (BackgroundInvoiceUploads) while
// this page polls its progress.
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

    /** Import-batch key of a big file being read / previewed / imported in the background. */
    public ?string $pending = null;

    public function mount(): void
    {
        // Coming back to the page: pick up this user's big upload that is still in flight.
        $this->pending = ImportBatch::where('user_id', auth()->id())
            ->whereIn('status', [BackgroundInvoiceUploads::ANALYZING, BackgroundInvoiceUploads::READY, BackgroundInvoiceUploads::IMPORTING])
            ->where('updated_at', '>', now()->subDay())
            ->latest('id')
            ->value('key');
        $this->refreshPending();
    }

    public function updatedFile(): void
    {
        $this->reset('preview', 'report', 'queued');
        $this->dropPending(); // a new file replaces a preview in progress
        $this->validate(['file' => 'required|file|max:'.(int) config('uploads.max_kilobytes')]);

        $ext = strtolower((string) $this->file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $this->addError('file', __('Please upload a .xlsx, .xls or .csv file.'));
            $this->reset('file');

            return;
        }

        $size = (int) $this->file->getSize();
        if (BackgroundInvoiceUploads::wants($ext, $size)) {
            $this->startBackground($ext);

            return;
        }
        if ($ext === 'xls' && $size > 25 * 1024 * 1024) {
            $this->addError('file', __('An .xls file can be up to 25 MB — save bigger files as .xlsx or .csv.'));
            $this->reset('file');

            return;
        }

        // A small file reads in seconds — kept under the nginx fastcgi_read_timeout of 120s.
        set_time_limit(110);
        $this->preview = app(InvoiceUploads::class)->preview($this->file->getRealPath());

        // More lines than the request handles, in a file that can be streamed → background.
        if (($this->preview['too_many'] ?? false) && in_array($ext, SheetStream::EXTENSIONS, true)) {
            $this->reset('preview');
            $this->startBackground($ext);
        }
    }

    public function import(InvoiceUploads $uploads): void
    {
        if ($this->pending !== null) {
            $batch = $this->pendingBatch();
            if ($batch !== null && app(BackgroundInvoiceUploads::class)->startImport($batch, $this->skipDuplicates)) {
                Audit::log('invoice.import', [
                    'file' => $batch->label,
                    'format' => $batch->format,
                    'batch' => $batch->key,
                    'skip_duplicates' => (bool) $this->skipDuplicates,
                    'lines' => $batch->meta['preview']['count'] ?? null,
                    'background' => true,
                ]);
                $this->reset('preview'); // the page now follows the import progress
            }

            return;
        }

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

        $this->followClassification($filename);
        $this->reset('file', 'preview');
    }

    /** Polled while a big file is read or imported: carry its preview / report into the page. */
    public function refreshPending(): void
    {
        $batch = $this->pendingBatch();
        if ($batch === null) {
            $this->pending = null;

            return;
        }

        $meta = $batch->meta ?? [];
        if ($batch->status === BackgroundInvoiceUploads::READY) {
            $this->preview ??= ['format' => $batch->format, 'label' => $batch->label] + ($meta['preview'] ?? []);
        } elseif ($batch->status === BackgroundInvoiceUploads::IMPORTED) {
            $this->report = $meta['report'] ?? null;
            $this->followClassification($batch->label);
            $this->pending = null;
        } elseif ($batch->status === BackgroundInvoiceUploads::FAILED) {
            $error = (string) ($meta['error'] ?? __('Import failed'));
            if (isset($meta['import'])) { // failed while importing — some rows may be in
                $this->report = ['format' => $batch->format, 'error' => $error, 'imported' => 0, 'skipped' => 0, 'total' => 0];
                $this->pending = null;
            } else {
                $this->preview ??= ['format' => $batch->format, 'label' => $batch->label, 'ok' => false, 'error' => $error, 'count' => 0, 'sample' => []];
            }
        }
    }

    public function startOver(): void
    {
        $this->dropPending();
        $this->reset('file', 'preview', 'report', 'skipDuplicates', 'queued', 'pending');
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
        $batch = $this->pending !== null ? $this->pendingBatch() : null;
        $background = null;
        if ($batch !== null && in_array($batch->status, [BackgroundInvoiceUploads::ANALYZING, BackgroundInvoiceUploads::IMPORTING], true)) {
            $meta = $batch->meta ?? [];
            $background = [
                'status' => $batch->status,
                'format' => $batch->format,
                'label' => $batch->label,
                'read' => (int) ($meta['read'] ?? 0),
                'done' => (int) (($meta['import']['imported'] ?? 0) + ($meta['import']['skipped'] ?? 0)),
                'total' => (int) ($meta['preview']['count'] ?? 0),
            ];
        }

        $step = $this->report ? 3 : ($this->preview ? 2 : 1);
        if ($background !== null) {
            $step = $background['status'] === BackgroundInvoiceUploads::ANALYZING ? 2 : 3;
        }

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
            'background' => $background,
            'existing' => (int) EInvoice::count(),
            'uploads' => app(InvoiceUploads::class)->recent(),
            'maxMegabytes' => (int) round(config('uploads.max_kilobytes') / 1024),
            'progress' => $progress,
            'headingNames' => $headingNames,
            'batchStats' => $batchStats,
        ]);
    }

    private function startBackground(string $ext): void
    {
        $batch = app(BackgroundInvoiceUploads::class)->start(
            $this->file->getRealPath(),
            (string) ($this->file->getClientOriginalName() ?: 'Invoice upload'),
            $ext,
            auth()->id(),
        );

        Audit::log('invoice.upload_background', ['file' => $batch->label, 'batch' => $batch->key, 'bytes' => (int) $this->file->getSize()]);

        $this->pending = $batch->key;
        $this->reset('file');
    }

    /** A line-level upload is classified on import — hand off to the live progress panel. */
    private function followClassification(string $label): void
    {
        if (($this->report['items'] ?? 0) > 0) {
            $this->queued = [
                'batch' => (string) $this->report['batch'],
                'count' => (int) $this->report['items'],
                'total' => (int) $this->report['items'],
                'source' => 'invoices',
                'label' => $label,
            ];
        }
    }

    /** Leave the current big upload: a preview nobody imported is removed, an import keeps running. */
    private function dropPending(): void
    {
        $batch = $this->pendingBatch();
        if ($batch !== null && in_array($batch->status, BackgroundInvoiceUploads::PENDING, true)) {
            app(BackgroundInvoiceUploads::class)->cancel($batch);
        }
        $this->pending = null;
    }

    private function pendingBatch(): ?ImportBatch
    {
        // $pending travels through the browser — only ever look up a real UUID key.
        return $this->pending !== null && Str::isUuid($this->pending)
            ? ImportBatch::where('key', $this->pending)->first()
            : null;
    }
}
