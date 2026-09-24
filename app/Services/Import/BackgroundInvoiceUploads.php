<?php

namespace App\Services\Import;

use App\Jobs\AnalyzeInvoiceUploadJob;
use App\Jobs\FeedUploadClassificationJob;
use App\Jobs\ImportInvoiceUploadJob;
use App\Models\ClassificationItem;
use App\Models\EInvoice;
use App\Models\ImportBatch;
use App\Services\Classify\ClassificationQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

// Big invoice files (above uploads.background_bytes) go through the SAME steps as a small
// upload — a preview with the counts, then Import — but the file is read as a stream
// (SheetStream) and both steps run on the worker while the Upload page polls the progress:
//
//   analyzing → ready (the preview) → importing → imported        (or failed)
//
// Reading is one pass that normalises every line once and writes it to an NDJSON file next to
// the upload, so the import never parses the xlsx again. The import commits in portions from a
// saved offset and hands over to its own continuation before the queue's retry_after, so it
// can always resume. The upload's item names are then fed to the (single) classification queue in small
// portions — Redis is capped and every other classification shares the queue.
class BackgroundInvoiceUploads
{
    public const ANALYZING = 'analyzing';

    public const READY = 'ready';

    public const IMPORTING = 'importing';

    public const IMPORTED = 'imported';

    public const FAILED = 'failed';

    /** Not imported yet: the upload's own preview — cancellable, pruned when stale. */
    public const PENDING = [self::ANALYZING, self::READY, self::FAILED];

    public function __construct(
        private readonly SheetStream $stream,
        private readonly InvoiceImporter $legacy,
        private readonly InvoiceLinesImporter $lines,
        private readonly ClassificationQueue $queue,
    ) {}

    /** Should a file of this type and size take the background path? */
    public static function wants(string $extension, int $bytes): bool
    {
        return in_array(strtolower($extension), SheetStream::EXTENSIONS, true)
            && $bytes > (int) config('uploads.background_bytes');
    }

    /** Keep the uploaded file where the worker can read it, and start reading it. */
    public function start(string $tmpPath, string $label, string $extension, ?int $userId): ImportBatch
    {
        $key = (string) Str::uuid();
        $file = $key.'.'.strtolower($extension);

        File::ensureDirectoryExists($this->directory());
        if (! copy($tmpPath, $this->path($file))) {
            throw new RuntimeException('Cannot store the uploaded file.');
        }

        $batch = ImportBatch::create([
            'key' => $key,
            'label' => $label,
            'source' => 'invoices',
            'user_id' => $userId,
            'item_count' => 0,
            'checksum' => (string) hash_file('sha256', $this->path($file)),
            'status' => self::ANALYZING,
            'meta' => ['file' => $file, 'read' => 0],
        ]);

        AnalyzeInvoiceUploadJob::dispatch($key);

        return $batch;
    }

    /**
     * Seconds one reading of a file may take (the job's own timeout). A 200 MB export reads in
     * ~4 min here; this leaves room for a server a few times slower.
     */
    public const READ_SECONDS = 1500;

    /**
     * Read the whole file in ONE pass (an xlsx stream cannot resume mid-file — a continuation
     * would re-parse everything read so far): recognise the layout, normalise and count every
     * line, write them to the NDJSON the import will use, and build the same preview a small
     * file gets. The pass may outlive the queue's retry_after, so a lock keeps a re-released copy
     * of the job from reading alongside it. Returns false when another reading holds the lock.
     */
    public function analyze(string $key): bool
    {
        $lock = Cache::lock('invoice-upload-analyze:'.$key, self::READ_SECONDS + 60);
        if (! $lock->get()) {
            return false;
        }

        try {
            $batch = ImportBatch::where('key', $key)->first();
            if ($batch !== null && $batch->status === self::ANALYZING) {
                $this->readFile($batch);
            }
        } finally {
            $lock->release();
        }

        return true;
    }

    private function readFile(ImportBatch $batch): void
    {
        $meta = $batch->meta ?? [];
        $source = $this->path((string) $meta['file']);
        $rows = $this->stream->rows($source, pathinfo($source, PATHINFO_EXTENSION));
        $header = $rows->valid() ? $rows->current() : [];
        $format = InvoiceLinesImporter::matches($header) ? 'sablon' : 'legacy';

        if ($format === 'legacy' && count($header) < count(InvoiceImporter::COLUMNS)) {
            $this->ready($batch, $meta, $format, ['ok' => false, 'error' => __('Unexpected columns: expected at least :min, got :got.', [
                'min' => count(InvoiceImporter::COLUMNS), 'got' => count($header),
            ]), 'count' => 0, 'duplicates' => 0, 'sample' => []]);

            return;
        }

        $map = $format === 'sablon'
            ? $this->lines->lineMapper($header)
            : fn (array $row) => $this->legacy->mapRow($row);
        $stats = new InvoiceLineStats;   // line-level export
        $keyCounts = [];                 // invoice list: series|number => rows
        $sample = [];
        $count = 0;
        $max = (int) config('uploads.max_lines');
        $tooMany = false;

        // 'w': a retry after a crash starts the file over — reading is idempotent.
        $out = fopen($this->path($batch->key.'.ndjson'), 'w');
        try {
            for ($rows->next(); $rows->valid(); $rows->next()) {
                $line = $map($rows->current(), $count + 1);
                if ($line === null) {
                    continue;
                }
                if (++$count > $max) {
                    $tooMany = true;
                    break;
                }

                if ($format === 'sablon') {
                    $stats->add($line);
                } elseif (($invoice = $this->legacy->naturalKey($line)) !== null) {
                    $keyCounts[$invoice] = ($keyCounts[$invoice] ?? 0) + 1;
                }
                fwrite($out, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n");
                if (count($sample) < 8) {
                    $sample[] = $line;
                }

                if ($count % 5000 === 0) {  // progress for the page (and a heartbeat for tend())
                    $meta['read'] = $count;
                    $batch->update(['meta' => $meta]);
                }
            }
        } finally {
            fclose($out);
        }

        $meta['read'] = min($count, $max);
        if ($tooMany) {
            $preview = ['ok' => false, 'error' => __('The file has more than :max lines.', ['max' => number_format($max, 0, '.', ' ')]), 'count' => $count, 'sample' => []];
        } elseif ($format === 'sablon') {
            $preview = $count === 0
                ? ['ok' => false, 'error' => __('No invoice lines found in the file.'), 'count' => 0, 'sample' => []]
                : $this->lines->preview($stats, (string) $batch->checksum, $sample, $batch->key);
        } else {
            // A row is a duplicate when its series|number is already loaded, or repeats in the file.
            $known = EInvoice::existingKeys(array_keys($keyCounts));
            $duplicates = 0;
            foreach ($keyCounts as $invoice => $n) {
                $duplicates += isset($known[$invoice]) ? $n : $n - 1;
            }
            $preview = ['ok' => true, 'error' => null, 'count' => $count, 'duplicates' => $duplicates, 'header' => $header, 'sample' => $sample];
        }

        $this->ready($batch, $meta, $format, $preview);
    }

    /** The user pressed Import on a ready preview. */
    public function startImport(ImportBatch $batch, bool $skipDuplicates): bool
    {
        if ($batch->status !== self::READY || ! ($batch->meta['preview']['ok'] ?? false)) {
            return false;
        }

        $meta = $batch->meta;
        $meta['import'] = ['skip' => $skipDuplicates, 'offset' => 0, 'imported' => 0, 'skipped' => 0];
        $batch->update(['status' => self::IMPORTING, 'meta' => $meta]);

        ImportInvoiceUploadJob::dispatch($batch->key);

        return true;
    }

    /**
     * Import from the saved offset, one transaction per portion, for up to uploads.job_seconds;
     * then either finish or hand over to a continuation job (still below retry_after).
     */
    public function import(string $key): void
    {
        // One importer per upload at a time — a resumed chain must never double-insert.
        $lock = Cache::lock('invoice-upload-import:'.$key, (int) config('uploads.job_seconds') + 120);
        if (! $lock->get()) {
            return;
        }

        $continue = false;
        try {
            $batch = ImportBatch::where('key', $key)->first();
            if ($batch === null || $batch->status !== self::IMPORTING) {
                return;
            }

            $path = $this->path($key.'.ndjson');
            $size = (int) filesize($path);
            $deadline = microtime(true) + (int) config('uploads.job_seconds');
            do {
                $done = $this->importPortion($batch, $path, $size);
            } while (! $done && microtime(true) < $deadline);

            if ($done) {
                $this->finish($batch);
            } else {
                $continue = true;
            }
        } finally {
            $lock->release();
        }

        if ($continue) {
            ImportInvoiceUploadJob::dispatch($key);
        }
    }

    /**
     * Put the next portion of the upload's classification on the queue — only while the queue is
     * shallow — and come back for the next one. Each portion is claimed with a compare-and-set on
     * fed_until_id, so a restarted feeding chain can never dispatch the same items twice.
     */
    public function feed(string $key): void
    {
        $batch = ImportBatch::where('key', $key)->first();
        if ($batch === null || $batch->status !== self::IMPORTED) {
            return;
        }

        $delay = (int) config('uploads.feed_delay_seconds');
        if (Queue::size() >= (int) config('uploads.feed_high_water')) {
            $batch->touch(); // alive — tend() only restarts a chain that went silent
            FeedUploadClassificationJob::dispatch($key)->delay(now()->addSeconds($delay));

            return;
        }

        $from = (int) $batch->fed_until_id;
        $items = ClassificationItem::where('batch', $key)
            ->where('id', '>', $from)
            ->orderBy('id')
            ->limit((int) config('uploads.feed_portion'))
            ->get();
        if ($items->isEmpty()) {
            return; // every item is on the queue — done
        }

        $claimed = ImportBatch::whereKey($batch->id)
            ->where(fn ($q) => $from === 0
                ? $q->whereNull('fed_until_id')->orWhere('fed_until_id', 0)
                : $q->where('fed_until_id', $from))
            ->update(['fed_until_id' => (int) $items->last()->id, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return; // another chain took this portion
        }

        $this->queue->dispatch($items);
        FeedUploadClassificationJob::dispatch($key)->delay(now()->addSeconds(1));
    }

    /**
     * Keep background uploads moving (scheduled): resume an import or a feeding chain that
     * went silent, fail a read that died, and remove previews nobody imported.
     */
    public function tend(): void
    {
        ImportBatch::where('status', self::IMPORTING)
            ->where('updated_at', '<', now()->subMinutes(10))
            ->each(fn (ImportBatch $b) => ImportInvoiceUploadJob::dispatch($b->key));

        ImportBatch::where('status', self::IMPORTED)
            ->where('item_count', '>', 0)
            ->whereBetween('updated_at', [now()->subDays(7), now()->subMinutes(10)])
            ->each(function (ImportBatch $b) {
                if (ClassificationItem::where('batch', $b->key)->where('id', '>', (int) $b->fed_until_id)->exists()) {
                    FeedUploadClassificationJob::dispatch($b->key);
                }
            });

        // A reading heartbeats every 5000 lines: silent for 20 min after it started means it died.
        // One that never started may just be waiting in a deep queue — give it hours.
        ImportBatch::where('status', self::ANALYZING)
            ->where('updated_at', '<', now()->subMinutes(20))
            ->get()
            ->filter(fn (ImportBatch $b) => ($b->meta['read'] ?? 0) > 0 || $b->updated_at < now()->subHours(3))
            ->each(fn (ImportBatch $b) => $this->fail($b, __('Reading the file stopped unexpectedly. Please upload it again.')));

        ImportBatch::whereIn('status', self::PENDING)
            ->where('updated_at', '<', now()->subHours((int) config('uploads.prune_after_hours')))
            ->each(fn (ImportBatch $b) => $this->cancel($b));
    }

    /** Mark an upload failed with a message the page shows. */
    public function fail(ImportBatch $batch, string $error): void
    {
        $meta = $batch->meta ?? [];
        $meta['error'] = $error;
        $batch->update(['status' => self::FAILED, 'meta' => $meta]);
        $this->removeFiles($batch);
    }

    /**
     * Drop an upload's files, and its row unless it already brought rows or items in (a failed
     * import keeps them — it is then listed and deleted like any other upload).
     */
    public function cancel(ImportBatch $batch): void
    {
        $this->removeFiles($batch);

        if (EInvoice::where('import_batch', $batch->key)->exists() || ClassificationItem::where('batch', $batch->key)->exists()) {
            return;
        }
        $batch->delete();
    }

    /** @return bool whether the whole file has been imported */
    private function importPortion(ImportBatch $batch, string $path, int $size): bool
    {
        $meta = $batch->meta;
        $import = $meta['import'];

        $fh = fopen($path, 'rb');
        fseek($fh, (int) $import['offset']);
        $lines = [];
        while (count($lines) < (int) config('uploads.slice_lines') && ($raw = fgets($fh)) !== false) {
            if (($raw = trim($raw)) !== '') {
                $lines[] = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            }
        }
        $offset = (int) ftell($fh);
        fclose($fh);

        // Lines and the new offset commit together — a retry resumes exactly after them.
        DB::transaction(function () use ($batch, $lines, $offset, $meta, $import) {
            $written = $batch->format === 'sablon'
                ? $this->lines->writeLines($lines, $batch->key, (bool) $import['skip'])
                : $this->legacy->writeRows($lines, $batch->key, (bool) $import['skip']);

            $import['offset'] = $offset;
            $import['imported'] += $written['imported'];
            $import['skipped'] += $written['skipped'];
            $meta['import'] = $import;
            $batch->update(['meta' => $meta]);
        });

        return $offset >= $size;
    }

    private function finish(ImportBatch $batch): void
    {
        $import = $batch->meta['import'];
        $agg = EInvoice::where('import_batch', $batch->key)
            ->selectRaw('count(*) as n, count(distinct invoice_key) as invoices, sum(case when invoice_key is null then 1 else 0 end) as unidentified')
            ->first();
        $items = ClassificationItem::where('batch', $batch->key)->count();

        $stats = [
            'lines' => (int) $agg->n,
            'invoices' => (int) $agg->invoices,
            'unidentified_lines' => (int) $agg->unidentified,
            'skipped' => (int) $import['skipped'],
        ];
        $meta = $batch->meta;
        $meta['report'] = [
            'format' => $batch->format,
            'batch' => $batch->key,
            'imported' => (int) $import['imported'],
            'skipped' => (int) $import['skipped'],
            'total' => (int) DB::table('e_invoices')->count(),
            'error' => null,
            'items' => $items,
            'stats' => $stats,
        ];

        $batch->update([
            'status' => self::IMPORTED,
            'item_count' => $items,
            'stats' => $stats,
            'meta' => $meta,
            // Nothing came in (every invoice was already loaded) — nothing to list or delete.
            'lines_deleted_at' => (int) $import['imported'] === 0 ? now() : null,
        ]);
        $this->removeFiles($batch);

        if ($items > 0) {
            FeedUploadClassificationJob::dispatch($batch->key);
        }
    }

    /** @param array<string, mixed> $meta */
    private function ready(ImportBatch $batch, array $meta, string $format, array $preview): void
    {
        $meta['preview'] = $preview;
        $batch->update(['status' => self::READY, 'format' => $format, 'meta' => $meta]);
    }

    private function removeFiles(ImportBatch $batch): void
    {
        foreach ([$batch->meta['file'] ?? null, $batch->key.'.ndjson'] as $file) {
            if ($file !== null && is_file($this->path($file))) {
                @unlink($this->path($file));
            }
        }
    }

    private function directory(): string
    {
        return (string) config('uploads.directory');
    }

    private function path(string $file): string
    {
        return $this->directory().'/'.basename($file);
    }
}
