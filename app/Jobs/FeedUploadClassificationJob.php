<?php

namespace App\Jobs;

use App\Services\Import\BackgroundInvoiceUploads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Puts the next portion of a big upload's classification on the queue while the queue is shallow,
 * then re-dispatches itself (see BackgroundInvoiceUploads::feed).
 */
class FeedUploadClassificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $batch) {}

    public function handle(BackgroundInvoiceUploads $uploads): void
    {
        $uploads->feed($this->batch);
    }
}
