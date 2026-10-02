<?php

namespace App\Jobs;

use App\Models\ClassificationItem;
use App\Models\TestRun;
use App\Services\Classify\Sorter;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * A test run's sorter step, as prod runs it (SortItemsJob): the items still in the pipeline
 * (memory and the trash rules did not answer them) are settled as trash when the sorter is
 * sure; the rest go on to the run's AI mechanisms, enlisted into THIS batch so the scorer
 * waits for them too. Every other item still gets a verdict — the run's "Sorter" column
 * scores the model on every row. A sorter that is down settles nothing: the items go to
 * the AI, as in prod.
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
     * @param  array<int, int>  $flow  those of them still in the pipeline (memory / the rules did not answer them)
     */
    public function __construct(public array $itemIds, public array $flow = []) {}

    public function handle(Sorter $sorter): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $items = ClassificationItem::whereIn('id', $this->itemIds)->orderBy('id')->get();
        $inFlow = fn (ClassificationItem $i) => in_array($i->id, $this->flow, true);

        $sorter->screen($items->reject($inFlow)->values()->all(), settleTrash: false);   // a verdict only
        $rest = $sorter->screen($items->filter($inFlow)->where('resolution', 'pending')->values()->all());

        // On the success path, before this job's completion is recorded — so the batch's
        // finally (the scorer) cannot fire before the mechanisms it enlists.
        $run = $items->isNotEmpty() ? TestRun::find($items->first()->test_run_id) : null;
        $jobs = ClassifyTestItemMechanismJob::for(
            array_map(fn (ClassificationItem $i) => $i->id, $rest),
            (array) ($run?->mechanisms['enabled'] ?? []),
        );
        if ($jobs !== []) {
            $this->batch()?->add($jobs);
        }
    }

    public function failed(?Throwable $e): void
    {
        // A hard kill (timeout / OOM). Laravel may already have counted this job as done for
        // the batch, so nothing is added to it now (that could fire the scorer twice). The
        // items it held never reach the AI: they end without an answer, so the run can finish,
        // and the (guarded, idempotent) scorer is asked again.
        foreach (ClassificationItem::whereIn('id', $this->flow)->where('resolution', 'pending')->get() as $item) {
            $item->update(['resolution' => 'no_match']);
            ClassificationItem::markAnswered($item->id);
        }
        $runId = ClassificationItem::whereIn('id', $this->itemIds)->value('test_run_id');
        if ($runId !== null) {
            ScoreRunJob::dispatch((int) $runId);
        }
    }
}
