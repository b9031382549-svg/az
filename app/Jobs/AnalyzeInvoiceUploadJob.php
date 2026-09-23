<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Import\BackgroundInvoiceUploads;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Reads a big invoice upload in one pass and builds its preview (see
 * BackgroundInvoiceUploads::analyze). A 200 MB file reads in minutes — its timeout is above the
 * queue's retry_after (360 s) ON PURPOSE: when the queue re-releases a copy of a reading still
 * in progress, the copy finds the upload's lock taken and checks back later instead of reading
 * alongside. A crash simply reads the file again from the start.
 */
class AnalyzeInvoiceUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = BackgroundInvoiceUploads::READ_SECONDS;

    /** A broken file fails after its second exception, not after two hours of retries. */
    public int $maxExceptions = 2;

    public function __construct(public string $batch) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(BackgroundInvoiceUploads $uploads): void
    {
        if (! $uploads->analyze($this->batch)) {
            $this->release(120); // another run is still reading this file — check back later
        }
    }

    public function failed(?Throwable $e): void
    {
        $batch = ImportBatch::where('key', $this->batch)->first();
        if ($batch !== null && $batch->status === BackgroundInvoiceUploads::ANALYZING) {
            app(BackgroundInvoiceUploads::class)->fail($batch, __('Cannot read file: :error', ['error' => $e?->getMessage() ?? '']));
        }
    }
}
