<?php

namespace App\Jobs;

use App\Models\ClassificationItem;
use App\Services\Classify\ClassificationQueue;
use App\Services\Classify\Sorter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The sorter step for a few items (see Sorter): their verdicts are stored, confident trash is
 * settled, and the rest are put on the AI mechanisms. On the queue rather than inline, so a
 * page request or an upload's feed never waits for a model running on the CPU. A job that
 * fails for good still sends its items on — no item is stranded 'pending' by the sorter.
 */
class SortItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Items per job — one sorter request of a few seconds on prod's CPU. */
    public const SIZE = 32;

    public int $tries = 2;

    /** Above the client's wait for the service (classify.sorter.timeout); below the queue's retry_after (prod: 360). */
    public int $timeout = 300;

    /** @param  array<int, int>  $itemIds */
    public function __construct(public array $itemIds) {}

    public function handle(Sorter $sorter, ClassificationQueue $queue): void
    {
        $queue->toMechanisms($sorter->screen($this->pendingItems()));
    }

    public function failed(?Throwable $e): void
    {
        app(ClassificationQueue::class)->toMechanisms($this->pendingItems());
    }

    /** @return array<int, ClassificationItem> still waiting (not settled meanwhile) */
    private function pendingItems(): array
    {
        return ClassificationItem::whereIn('id', $this->itemIds)
            ->where('resolution', 'pending')
            ->orderBy('id')
            ->get()
            ->all();
    }
}
