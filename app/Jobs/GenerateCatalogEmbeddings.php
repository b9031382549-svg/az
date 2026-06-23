<?php

namespace App\Jobs;

use App\Services\Embeddings\CatalogEmbeddingRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Embeds one batch of catalog rows, then re-dispatches itself while work
 * remains. Small units keep each job short — clean restart behaviour (a worker
 * restart simply resumes from the next pending rows) without timeout pitfalls.
 */
class GenerateCatalogEmbeddings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 600;

    public function __construct(public int $chunk = 16, public ?string $refreshBefore = null) {}

    public function handle(CatalogEmbeddingRunner $runner): void
    {
        if ($this->refreshBefore !== null) {
            $done = $runner->refreshBatch($this->refreshBefore, $this->chunk);

            if ($done > 0 && $runner->staleCount($this->refreshBefore) > 0) {
                self::dispatch($this->chunk, $this->refreshBefore);
            }

            return;
        }

        $done = $runner->embedBatch($this->chunk);

        if ($done > 0 && $runner->pendingCount() > 0) {
            self::dispatch($this->chunk);
        }
    }
}
