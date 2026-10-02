<?php

namespace App\Jobs;

use App\Services\Api\ClassifyRequests;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Turns a saved POST /api/classify body into items on the pipeline (ClassifyRequests::ingest). */
class IngestApiRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // 100k names take well under a minute; stays below the queue's retry_after.
    public int $timeout = 300;

    public function __construct(public string $batch) {}

    public function handle(ClassifyRequests $requests): void
    {
        $requests->ingest($this->batch);
    }

    public function failed(?Throwable $e): void
    {
        $requests = app(ClassifyRequests::class);
        if ($batch = $requests->find($this->batch)) {
            $requests->fail($batch, 'The request could not be read: '.($e?->getMessage() ?? 'unknown error'));
        }
    }
}
