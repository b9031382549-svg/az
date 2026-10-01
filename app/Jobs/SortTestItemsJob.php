<?php

namespace App\Jobs;

use App\Models\ClassificationItem;
use App\Services\Classify\Sorter;
use App\Services\Classify\TrashFilter;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The sorter's verdicts for a few test-run items — the run's "Sorter" column. Verdicts only:
 * a test run settles no code-scored row by them (it runs no trash step). Rows that carry
 * only a kind (TRASH / GOOD, no code) run nothing else, so they are settled here — trash when
 * the sorter is sure, as prod would; otherwise no_match — and the run can finish.
 */
class SortTestItemsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Items per job — one sorter request of a few seconds on prod's CPU. */
    public const SIZE = 32;

    public int $tries = 2;

    /** Above the client's wait for the service (classify.sorter.timeout); below the queue's retry_after (prod: 360). */
    public int $timeout = 300;

    /**
     * @param  array<int, int>  $itemIds  every item to sort
     * @param  array<int, int>  $kindOnly  those of them no other step looks at (no code expected)
     */
    public function __construct(public array $itemIds, public array $kindOnly = []) {}

    public function handle(Sorter $sorter): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $sorter->screen(ClassificationItem::whereIn('id', $this->itemIds)->orderBy('id')->get()->all(), settleTrash: false);
        self::settleKindOnly($this->kindOnly);
    }

    public function failed(?Throwable $e): void
    {
        // Never strand a run: settle what only the sorter would have settled, then let the
        // (guarded, idempotent) scorer try again.
        self::settleKindOnly($this->kindOnly);
        $runId = ClassificationItem::whereIn('id', $this->itemIds)->value('test_run_id');
        if ($runId !== null) {
            ScoreRunJob::dispatch((int) $runId);
        }
    }

    /** @param  array<int, int>  $ids */
    public static function settleKindOnly(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $trash = app(TrashFilter::class);
        foreach (ClassificationItem::whereIn('id', $ids)->where('resolution', 'pending')->with('results')->get() as $item) {
            $verdict = $item->results->firstWhere('mechanism', 'sorter');
            $p = (float) data_get($verdict?->trace, 'probs.trash', 0.0);
            $threshold = (float) data_get($verdict?->trace, 'trash_threshold', config('classify.sorter.trash_threshold'));
            if ($verdict !== null && $p >= $threshold && $trash->settle($item, 'sorter', ['p' => round($p, 6)])) {
                continue;
            }
            $item->update(['resolution' => 'no_match']);
            ClassificationItem::markAnswered($item->id);
        }
    }
}
