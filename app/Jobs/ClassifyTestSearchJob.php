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
use Throwable;

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

    /**
     * A hard kill (timeout / OOM): the understanding, three votes and the web search can
     * together outlast the job. Record the abstention the resolver writes when the search is
     * unavailable — without a 'search' row the run would wait for this search forever
     * (RunScorer::isSettled) — and score again: the batch's finally may already have fired.
     */
    public function failed(?Throwable $e): void
    {
        $item = ClassificationItem::find($this->itemId);
        if ($item === null) {
            return;
        }

        if ($item->resolution === 'conflict' && ! $item->results()->where('mechanism', 'search')->exists()) {
            $item->results()->create([
                'mechanism' => 'search', 'matched_code' => null, 'catalog_id' => null, 'kind' => null,
                'status' => 'no_match', 'confidence' => null, 'candidates' => [], 'trace' => [],
                'explanation' => 'Search resolver unavailable (the job failed).',
                'model' => (string) config('classify.search_resolver.model'),
            ]);
        }
        if ($item->test_run_id !== null) {
            ScoreRunJob::dispatch((int) $item->test_run_id);
        }
    }
}
