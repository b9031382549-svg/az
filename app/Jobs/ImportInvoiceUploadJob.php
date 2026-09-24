<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Import\BackgroundInvoiceUploads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Imports a big invoice upload from its saved offset (see BackgroundInvoiceUploads::import).
 * Works ~4 min per run and hands over to a continuation, so each run ends well below the queue's
 * retry_after; a retry after a crash resumes exactly after the last committed portion.
 */
class ImportInvoiceUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 330;

    public int $backoff = 30;

    public function __construct(public string $batch) {}

    public function handle(BackgroundInvoiceUploads $uploads): void
    {
        $uploads->import($this->batch);
    }

    public function failed(?Throwable $e): void
    {
        $batch = ImportBatch::where('key', $this->batch)->first();
        if ($batch !== null && $batch->status === BackgroundInvoiceUploads::IMPORTING) {
            app(BackgroundInvoiceUploads::class)->fail($batch, __('Import failed: :error', ['error' => $e?->getMessage() ?? '']));
        }
    }
}
