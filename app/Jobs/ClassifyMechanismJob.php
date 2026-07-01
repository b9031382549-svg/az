<?php

namespace App\Jobs;

use App\Models\ClassificationItem;
use App\Services\Classify\Consensus;
use App\Services\Classify\Mechanisms\MechanismRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs ONE search mechanism for ONE item, stores its result, and re-checks the
 * item's consensus. One short job per (item, mechanism) — restart-safe and
 * idempotent (the unique(classification_item_id, mechanism) index is the guard).
 * The last mechanism to finish is the one whose Consensus::finalize() sets the
 * terminal resolution; a permanently-failed mechanism records an abstaining
 * error result so the item never stays stuck 'pending'.
 */
class ClassifyMechanismJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $itemId, public string $mechanism) {}

    public function handle(MechanismRegistry $registry, Consensus $consensus): void
    {
        $item = ClassificationItem::find($this->itemId);
        if ($item === null || ! $registry->has($this->mechanism)) {
            return;
        }

        if (! $item->results()->where('mechanism', $this->mechanism)->exists()) {
            $result = $registry->get($this->mechanism)->classify((string) $item->source_text);
            $item->results()->updateOrCreate(['mechanism' => $this->mechanism], $result->toRow());
        }

        $consensus->finalize($item);
    }

    public function failed(Throwable $e): void
    {
        $item = ClassificationItem::find($this->itemId);
        if ($item === null) {
            return;
        }

        // Abstaining error result — keeps the item from blocking consensus forever.
        $item->results()->updateOrCreate(
            ['mechanism' => $this->mechanism],
            ['status' => 'error', 'matched_code' => null, 'explanation' => mb_substr($e->getMessage(), 0, 500)],
        );

        app(Consensus::class)->finalize($item);
    }
}
