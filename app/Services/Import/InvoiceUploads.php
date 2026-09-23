<?php

namespace App\Services\Import;

use App\Models\EInvoice;
use App\Models\ImportBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

// The Upload page's single entry point for invoice files. Reads a file once, recognises its
// layout — the line-level export ("Şablon", classified on import) or the legacy 15-column
// invoice list — and hands it to the matching importer. Every upload is an import batch
// (source 'invoices'), so it can be listed and deleted on its own.
class InvoiceUploads
{
    public function __construct(
        private readonly SheetReader $reader,
        private readonly InvoiceImporter $legacy,
        private readonly InvoiceLinesImporter $lines,
    ) {}

    /**
     * @return array<string, mixed> the importer's preview plus 'format' (sablon | legacy | null)
     */
    public function preview(string $path): array
    {
        try {
            [$header, $rows] = $this->reader->read($path);
        } catch (Throwable $e) {
            return ['format' => null, 'ok' => false, 'error' => __('Cannot read file: :error', ['error' => $e->getMessage()]), 'count' => 0, 'duplicates' => 0, 'sample' => []];
        }

        if (InvoiceLinesImporter::matches($header)) {
            return ['format' => 'sablon'] + $this->lines->previewRows($header, $rows, (string) hash_file('sha256', $path));
        }

        return ['format' => 'legacy'] + $this->legacy->previewRows($header, $rows);
    }

    /**
     * @return array<string, mixed> the importer's report plus 'format' and 'batch'
     */
    public function import(string $path, string $label, ?int $userId, bool $skipDuplicates): array
    {
        try {
            [$header, $rows] = $this->reader->read($path);
        } catch (Throwable $e) {
            return ['format' => null, 'batch' => null, 'imported' => 0, 'skipped' => 0, 'total' => (int) DB::table('e_invoices')->count(), 'error' => __('Cannot read file: :error', ['error' => $e->getMessage()])];
        }

        $checksum = (string) hash_file('sha256', $path);

        if (InvoiceLinesImporter::matches($header)) {
            return ['format' => 'sablon'] + $this->lines->importRows($header, $rows, $checksum, $label, $userId, $skipDuplicates);
        }

        // Legacy: the invoice list exactly as before, now stamped with its own batch.
        $batch = (string) Str::uuid();
        $report = DB::transaction(function () use ($header, $rows, $skipDuplicates, $batch, $label, $userId, $checksum) {
            $report = $this->legacy->importRows($header, $rows, $skipDuplicates, $batch);
            if ($report['error'] === null && $report['imported'] > 0) {
                ImportBatch::create([
                    'key' => $batch,
                    'label' => $label,
                    'source' => 'invoices',
                    'format' => 'legacy',
                    'user_id' => $userId,
                    'item_count' => 0,
                    'checksum' => $checksum,
                    'stats' => ['lines' => $report['imported'], 'skipped' => $report['skipped']],
                ]);
            }

            return $report;
        });

        return ['format' => 'legacy', 'batch' => $report['imported'] > 0 ? $batch : null] + $report;
    }

    /**
     * Invoice uploads whose lines are still in the table, newest first.
     *
     * @return Collection<int, object>
     */
    public function recent(int $limit = 10): Collection
    {
        $batches = ImportBatch::where('source', 'invoices')
            ->whereNull('lines_deleted_at')
            ->latest('id')
            ->limit($limit)
            ->get();

        $lines = EInvoice::whereIn('import_batch', $batches->pluck('key'))
            ->selectRaw('import_batch, count(*) as c')
            ->groupBy('import_batch')
            ->pluck('c', 'import_batch');

        return $batches->map(fn (ImportBatch $b) => (object) [
            'key' => $b->key,
            'label' => $b->label,
            'format' => $b->format,
            'lines' => (int) ($lines[$b->key] ?? 0),
            'items' => (int) $b->item_count,
            'at' => $b->created_at,
        ]);
    }

    /**
     * Delete one upload's invoice lines. Its classification is kept — Review keeps the run and
     * memory keeps what was learned — so the batch row stays too while it labels that run.
     */
    public function delete(string $key): int
    {
        if (! Str::isUuid($key)) {
            return 0;
        }

        $deleted = EInvoice::where('import_batch', $key)->delete();
        $batch = ImportBatch::where('key', $key)->where('source', 'invoices')->first();
        if ($batch) {
            $this->retire($batch);
        }

        return $deleted;
    }

    /** The page's "Delete all invoices": empty the table and retire every invoice upload. */
    public function deleteAll(): int
    {
        $deleted = $this->legacy->deleteAll();

        ImportBatch::where('source', 'invoices')->whereNull('lines_deleted_at')->get()
            ->each(fn (ImportBatch $b) => $this->retire($b));

        return $deleted;
    }

    /** A batch that still labels a classification run is only marked; an empty one goes. */
    private function retire(ImportBatch $batch): void
    {
        $batch->items()->exists()
            ? $batch->update(['lines_deleted_at' => now()])
            : $batch->delete();
    }
}
