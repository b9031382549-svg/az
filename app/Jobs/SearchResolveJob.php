<?php

namespace App\Jobs;

use App\Models\ClassificationItem;
use App\Services\Classify\SearchResolverService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the web-search resolver for ONE conflicting item — the last resort after the
 * three mechanisms diverge. Dispatched once per item by Consensus::finalize() (guarded
 * by the search_resolved_at atomic claim). Confident+real heading → the service flips
 * the item to 'ai_resolved' at that 4-digit heading; otherwise it stays 'conflict' for
 * a human, with the search attempt recorded.
 */
class SearchResolveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ONE attempt only: the paid :online call has already happened by the time any
    // downstream error could throw, so a retry would re-issue (and re-bill) the search.
    // LLM/timeout failures are already swallowed inside the resolver (it abstains), and
    // a claimed-but-unfinished item is recovered by the reaper (classify:reap-search-resolves).
    public int $tries = 1;

    public int $timeout = 300; // web search + reasoning; keep REDIS_QUEUE_RETRY_AFTER above this

    public function __construct(public int $itemId) {}

    public function handle(SearchResolverService $resolver): void
    {
        if (! (bool) config('classify.search_resolver.enabled', false)) {
            return;
        }

        $item = ClassificationItem::find($this->itemId);
        if ($item === null) {
            return;
        }

        // Re-check against fresh state: a human may have decided while this was queued.
        if ($item->resolution !== 'conflict') {
            return;
        }

        $resolver->resolve($item);
    }
}
