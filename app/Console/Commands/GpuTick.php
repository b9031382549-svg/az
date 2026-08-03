<?php

namespace App\Console\Commands;

use App\Services\Gpu\GpuCoordinator;
use Illuminate\Console\Command;

/**
 * Advance the A/B GPU state machine by one bounded pass (safety + poll every in-flight slot
 * and run). Deliberately NOT registered in the scheduler yet (per decision) — run it by
 * hand or via RunGpuTickJob while we exercise the flow. Each pass is short (health probes +
 * launch-and-return calls); the long boots/trainings advance across repeated passes.
 */
class GpuTick extends Command
{
    protected $signature = 'gpu:tick';

    protected $description = 'Advance the A/B GPU orchestration state machine one step';

    public function handle(GpuCoordinator $coordinator): int
    {
        $moved = $coordinator->tick();
        $this->info("gpu:tick — advanced {$moved['servers']} server(s), {$moved['runs']} run(s).");

        return self::SUCCESS;
    }
}
