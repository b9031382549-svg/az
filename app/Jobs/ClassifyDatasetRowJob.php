<?php

namespace App\Jobs;

use App\Models\ClassificationItem;
use App\Models\TestDatasetRow;
use App\Models\TestRun;
use App\Services\Testing\DatasetRowClassifier;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Classifies ONE dataset row for ONE test run, on the dedicated 'testing' queue so
 * the per-run config([...]) override can never bleed into a prod classification job
 * (a queue worker does not re-bootstrap between jobs). The snapshot is applied at the
 * top and RESTORED in finally as a second guard for test-to-test isolation.
 *
 * tries=1: the row bundles paid LLM calls (broker descent + :online search) that must
 * not be re-billed by a retry — a failure records a terminal row via failed() instead.
 */
class ClassifyDatasetRowJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $runId, public int $rowId) {}

    public function handle(DatasetRowClassifier $classifier): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = TestRun::find($this->runId);
        $row = TestDatasetRow::find($this->rowId);
        if ($run === null || $row === null) {
            return;
        }

        // Snapshot the exact config keys this run overrides, apply the run's snapshot,
        // and restore afterwards no matter what — the worker must leave with the config
        // it arrived with.
        $original = [];
        foreach (array_keys($run->config ?? []) as $key) {
            $original[$key] = config($key);
        }

        try {
            config($run->config ?? []);
            $classifier->run($this->itemFor($run, $row), $this->flags($run), (int) $run->test_dataset_id);
        } finally {
            config($original);
        }
    }

    /** Create (or reuse, on a re-run) this run's scratch item for the row. */
    private function itemFor(TestRun $run, TestDatasetRow $row): ClassificationItem
    {
        // A run-namespaced hash: distinct per run AND from any prod item of the same
        // text, so the source_hash-keyed caches (product_briefs / facts / translations)
        // stay isolated and each run regenerates its own brief. The dataset↔run join is
        // by test_dataset_row_id, so the synthetic hash never affects scoring.
        $hash = hash('sha256', $run->batch.'|'.$row->source_text);

        return ClassificationItem::firstOrCreate(
            ['batch' => $run->batch, 'source_hash' => $hash],
            [
                'test_run_id' => $run->id,
                'test_dataset_row_id' => $row->id,
                'source_text' => $row->source_text,
                'resolution' => 'pending',
            ],
        );
    }

    /**
     * @return array{enabled:array<int,string>, shadow:array<int,string>, cache:bool, search:bool}
     */
    private function flags(TestRun $run): array
    {
        $m = $run->mechanisms ?? [];

        return [
            'enabled' => (array) ($m['enabled'] ?? ['vector', 'broker', 'direct']),
            'shadow' => (array) ($m['shadow'] ?? []),
            'cache' => (bool) ($m['cache'] ?? false),
            'search' => (bool) ($m['search'] ?? false),
        ];
    }

    public function failed(Throwable $e): void
    {
        $run = TestRun::find($this->runId);
        $row = TestDatasetRow::find($this->rowId);
        if ($run === null || $row === null) {
            return;
        }

        // Mark the item terminal so batch progress can reach 100% and the scorer counts
        // it (as a miss) rather than the run hanging on a 'pending' row forever.
        $hash = hash('sha256', $run->batch.'|'.$row->source_text);
        ClassificationItem::updateOrCreate(
            ['batch' => $run->batch, 'source_hash' => $hash],
            [
                'test_run_id' => $run->id,
                'test_dataset_row_id' => $row->id,
                'source_text' => $row->source_text,
                'resolution' => 'no_match',
            ],
        );
    }
}
