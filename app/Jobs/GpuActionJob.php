<?php

namespace App\Jobs;

use App\Models\FinetuneAdapter;
use App\Models\GpuServer;
use App\Services\Gpu\FinetuneManager;
use App\Services\Gpu\GpuCoordinator;
use App\Services\Gpu\GpuServerManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One bounded GPU control action, dispatched by a UI button (each call is a launch-and-
 * return or a single tick — never training-length, so it stays well under the queue's
 * timeout and can't trip REDIS_QUEUE_RETRY_AFTER). Actions:
 *   provision-serving  — bring a slot up to serve $adapterId
 *   serve-base         — bring a slot up serving the base model only (no adapter/training)
 *   start-finetune     — export corpus + provision a training slot + begin a run; trains on
 *                        the production corpus by default, or ONE Testing dataset's own
 *                        isolated memory when $datasetId is given (an experiment/comparison
 *                        cycle — see FinetuneManager::start())
 *   destroy            — tear a slot down (idempotent)
 *   tick               — advance the whole state machine one pass
 *
 * The zero-downtime SWITCH is NOT here: it's an instant in-transaction flag flip done
 * synchronously from the Livewire component, which then dispatches a `destroy` for the old
 * slot. On failure the managers park the slot/run in `error`/`failed` with the message, so
 * a dead job never leaves the UI without an explanation.
 */
class GpuActionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;   // control actions are not safely auto-retryable (side effects)

    public int $timeout = 320; // one orchestrator subprocess (config gpu.process_timeout) + slack

    public function __construct(
        public string $action,
        public ?int $serverId = null,
        public ?int $adapterId = null,
        public ?int $datasetId = null,
    ) {}

    public function handle(GpuServerManager $servers, FinetuneManager $finetune, GpuCoordinator $coordinator): void
    {
        if ($this->action === 'tick') {
            // Clear the page's single-flight flag when done so the next poll can dispatch
            // the following tick immediately — giving near-continuous progress updates.
            try {
                $coordinator->tick();
            } finally {
                Cache::forget('gpu:tick:queued');
            }

            return;
        }

        $server = $this->serverId ? GpuServer::find($this->serverId) : null;
        if (! $server) {
            return;
        }

        match ($this->action) {
            'provision-serving' => $servers->provisionServing($server, FinetuneAdapter::findOrFail($this->adapterId)),
            'serve-base' => $servers->provisionBaseServing($server),
            'start-finetune' => $finetune->start($server, $this->datasetId),
            'destroy' => $servers->destroy($server),
            default => null,
        };
    }

    public function failed(Throwable $e): void
    {
        // The managers already park state on their own throws; this catches a job-level
        // crash (e.g. OOM) so the slot doesn't sit in a transient status forever.
        if ($this->serverId && ($server = GpuServer::find($this->serverId))) {
            if (! in_array($server->status, [GpuServer::STATUS_OFF, GpuServer::STATUS_SERVING, GpuServer::STATUS_READY_TO_SWITCH], true)) {
                $server->update(['status' => GpuServer::STATUS_ERROR, 'status_detail' => 'Job failed: '.mb_substr($e->getMessage(), 0, 400)]);
            }
        }
    }
}
