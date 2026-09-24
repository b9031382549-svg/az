<?php

namespace App\Jobs;

use App\Services\Import\BackgroundInvoiceUploads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduled housekeeping for big uploads (see BackgroundInvoiceUploads::tend). Runs as a QUEUED
 * job on the worker — the only container besides app with the uploads volume, so it can remove
 * the files of previews nobody imported.
 */
class TendInvoiceUploadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(BackgroundInvoiceUploads $uploads): void
    {
        $uploads->tend();
    }
}
