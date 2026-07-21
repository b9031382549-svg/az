<?php

namespace App\Jobs;

use App\Models\TestRun;
use App\Services\Testing\RunScorer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs once the row batch finishes (dispatched from the batch's finally callback, so
 * it fires even if some rows failed). Computes and persists the run's accuracy.
 */
class ScoreRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $runId) {}

    public function handle(RunScorer $scorer): void
    {
        $run = TestRun::find($this->runId);
        if ($run === null) {
            return;
        }

        $scorer->finalize($run);
    }
}
