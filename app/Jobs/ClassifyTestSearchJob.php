<?php

namespace App\Jobs;

use App\Models\ClassificationItem;
use App\Services\Classify\SearchResolverService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The web-search tie-breaker for a divergent dataset-test item — the prod
 * SearchResolverService, unchanged. Added to the run's batch (by the mechanism job that
 * saw the conflict), so the batch's scorer waits for it. tries=1: the paid :online call
 * has already fired before most errors, so a retry would just re-bill.
 */
class ClassifyTestSearchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $itemId) {}

    public function handle(SearchResolverService $resolver): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $item = ClassificationItem::find($this->itemId);
        if ($item === null || $item->resolution !== 'conflict') {
            return;
        }

        $resolver->resolve($item);
    }
}
