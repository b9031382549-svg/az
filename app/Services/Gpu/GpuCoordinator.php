<?php

namespace App\Services\Gpu;

use App\Models\FinetuneRun;
use App\Models\GpuServer;
use Illuminate\Support\Facades\Cache;

/**
 * One bounded pass of the whole A/B state machine: enforce the "forgot" safety, then
 * advance every in-flight server slot and training run by a single step. Invoked by the
 * gpu:tick command / RunGpuTickJob (NOT scheduled yet, per decision) — repeated ticks are
 * what carry the long boots/trainings forward without any long-running job.
 */
class GpuCoordinator
{
    public function __construct(
        private readonly GpuServerManager $servers,
        private readonly FinetuneManager $finetune,
    ) {}

    /** @return array{servers: int, runs: int, skipped?: bool} how many entities were advanced */
    public function tick(): array
    {
        // Single-flight across ALL sources (page poll, my CLI loop, future scheduler): a
        // second concurrent tick could call serveBase/deployAdapter twice and restart a
        // cold-loading vLLM. If a tick is already running, skip — the next poll retries.
        $lock = Cache::lock('gpu:coordinator:tick', 300);
        if (! $lock->get()) {
            return ['servers' => 0, 'runs' => 0, 'skipped' => true];
        }

        try {
            return $this->run();
        } finally {
            $lock->release();
        }
    }

    /** @return array{servers: int, runs: int} */
    private function run(): array
    {
        $this->servers->enforceSafety();

        // Serving-path slots mid-lifecycle (a run's own training slot is advanced by the
        // run poll instead, to keep training state in one place).
        $servingStatuses = [
            GpuServer::STATUS_PROVISIONING,
            GpuServer::STATUS_BOOTING,
            GpuServer::STATUS_DEPLOYING_ADAPTER,
        ];
        $servers = GpuServer::query()
            ->whereIn('status', $servingStatuses)
            ->whereNull('current_run_id')
            ->get();
        foreach ($servers as $server) {
            $this->servers->poll($server);
        }

        $runs = FinetuneRun::query()
            ->whereNotIn('status', [FinetuneRun::STATUS_DONE, FinetuneRun::STATUS_FAILED])
            ->get();
        foreach ($runs as $run) {
            $this->finetune->poll($run);
        }

        return ['servers' => $servers->count(), 'runs' => $runs->count()];
    }
}
