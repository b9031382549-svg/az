<?php

namespace App\Services\Testing;

use App\Jobs\ClassifyDatasetRowJob;
use App\Jobs\ScoreRunJob;
use App\Models\TestDataset;
use App\Models\TestRun;
use Illuminate\Support\Facades\Bus;
use RuntimeException;

/**
 * Creates a test run and fans its rows out as a batch on the dedicated 'testing'
 * queue. Snapshots the FULL effective classify.* config (models AND retrieval flags)
 * so a later "before/after" reflects the code change, not silent config drift.
 */
class TestRunner
{
    /** The per-row job timeout; retry_after must exceed this or paid calls double-fire. */
    private const ROW_TIMEOUT = 600;

    /**
     * @param  array{enabled:array<int,string>, shadow?:array<int,string>, cache?:bool, search?:bool}  $mechanisms
     */
    public function launch(TestDataset $dataset, string $description, array $mechanisms): TestRun
    {
        $this->assertQueueSafe();

        $run = new TestRun;
        $run->test_dataset_id = $dataset->id;
        $run->description = trim($description);
        $run->mechanisms = $this->normalizeMechanisms($mechanisms);
        $run->config = $this->configSnapshot();
        $run->status = 'pending';
        $run->total = $dataset->scorableRows()->count();
        $run->save();

        // batch key needs the id; set it now that we have one.
        $run->update(['batch' => TestRun::batchKey($run->id)]);

        $jobs = $dataset->scorableRows()->pluck('id')
            ->map(fn ($id) => new ClassifyDatasetRowJob($run->id, (int) $id))
            ->all();

        Bus::batch($jobs)
            ->name($run->batch)
            ->onQueue('testing')
            ->allowFailures()   // one bad row must not cancel the rest
            ->finally(fn () => ScoreRunJob::dispatch($run->id)) // fires even with failures
            ->dispatch();

        $run->update(['status' => 'running', 'started_at' => now()]);

        return $run;
    }

    /**
     * @param  array{enabled:array<int,string>, shadow?:array<int,string>, cache?:bool, search?:bool}  $m
     * @return array{enabled:array<int,string>, shadow:array<int,string>, cache:bool, search:bool}
     */
    private function normalizeMechanisms(array $m): array
    {
        return [
            'enabled' => array_values(array_intersect(['vector', 'broker', 'direct'], (array) ($m['enabled'] ?? []))),
            'shadow' => array_values((array) ($m['shadow'] ?? [])),
            'cache' => (bool) ($m['cache'] ?? false),
            'search' => (bool) ($m['search'] ?? false),
        ];
    }

    /** Whole classify subtree + the two rerank model ids the vector mechanism uses. */
    private function configSnapshot(): array
    {
        return [
            'classify' => config('classify'),
            'services.openrouter.classify_model' => config('services.openrouter.classify_model'),
            'services.openrouter.classify_model_tier1' => config('services.openrouter.classify_model_tier1'),
        ];
    }

    /**
     * Fail fast on a config that would re-bill paid LLM calls: on redis, a job still
     * running when retry_after elapses is re-dispatched (a duplicate paid run). Only
     * matters for the real redis queue — the sync/array test queue is exempt.
     */
    private function assertQueueSafe(): void
    {
        if (config('queue.default') !== 'redis') {
            return;
        }
        $retryAfter = (int) config('queue.connections.redis.retry_after', 90);
        if ($retryAfter < self::ROW_TIMEOUT) {
            throw new RuntimeException(
                "REDIS_QUEUE_RETRY_AFTER ({$retryAfter}s) must be >= the row-job timeout ("
                .self::ROW_TIMEOUT.'s) or a slow paid job re-dispatches while still running. '
                .'Raise REDIS_QUEUE_RETRY_AFTER and redeploy/optimize before running a dataset.'
            );
        }
    }
}
