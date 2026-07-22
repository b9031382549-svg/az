<?php

namespace App\Services\Testing;

use App\Jobs\ClassifyTestItemMechanismJob;
use App\Jobs\ScoreRunJob;
use App\Models\ClassificationItem;
use App\Models\TestDataset;
use App\Models\TestRun;
use App\Services\Classify\AnswerCacheService;
use Illuminate\Support\Facades\Bus;

/**
 * Launches a dataset test run as the PRODUCTION pipeline: one short mechanism job per
 * (item, mechanism) on the normal 'default' queue, reconciled against the run's chosen
 * mechanism set. No per-run config override and no dedicated queue — the run reads the
 * live prod config exactly like a real classification, so nothing can drift or "leak".
 *
 * `config` is snapshotted only as a RECORD (what models/flags this run used) for reading
 * an A/B comparison later — it is never applied.
 */
class TestRunner
{
    public function __construct(private readonly AnswerCacheService $cache) {}

    /**
     * @param  array{enabled:array<int,string>, shadow?:array<int,string>, cache?:bool, search?:bool}  $mechanisms
     */
    public function launch(TestDataset $dataset, string $description, array $mechanisms): TestRun
    {
        $run = new TestRun;
        $run->test_dataset_id = $dataset->id;
        $run->description = trim($description);
        $run->mechanisms = $this->normalizeMechanisms($mechanisms);
        $run->config = $this->configSnapshot();
        $run->status = 'running';
        $run->total = $dataset->scorableRows()->count();
        $run->started_at = now();
        $run->save();
        $run->update(['batch' => TestRun::batchKey($run->id)]);

        $useCache = $run->mechanisms['cache'];
        $enabled = $run->mechanisms['enabled'];

        $jobs = [];
        foreach ($dataset->scorableRows()->orderBy('id')->get() as $row) {
            $item = ClassificationItem::create([
                'batch' => $run->batch,
                'test_run_id' => $run->id,
                'test_dataset_row_id' => $row->id,
                'source_text' => $row->source_text,
                // The item's identity, unique per run row (so duplicate names in a dataset
                // don't collide on unique(batch, source_hash)). Briefs/facts are keyed by
                // TEXT inside their own services, so a test run SHARES them with production —
                // which is exactly what "faithful to prod" wants; source_hash doesn't change that.
                'source_hash' => hash('sha256', $run->batch.'|row|'.$row->id),
                'resolution' => 'pending',
            ]);

            // memory-on: dataset-scoped cache short-circuit, exactly like prod's cache-first step.
            if ($useCache && $this->cache->apply($item, $run->test_dataset_id)) {
                continue; // hit → terminal, no mechanism jobs
            }
            foreach ($enabled as $mech) {
                $jobs[] = new ClassifyTestItemMechanismJob($item->id, $mech);
            }
        }

        if ($jobs === []) {
            ScoreRunJob::dispatch($run->id); // all cache hits (or nothing to run) — score now

            return $run;
        }

        Bus::batch($jobs)
            ->name($run->batch)
            ->allowFailures()  // one bad job must not cancel the rest
            ->finally(fn () => ScoreRunJob::dispatch($run->id)) // fires after mechanisms AND searches
            ->dispatch();

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

    /** Whole classify subtree + the two rerank model ids — RECORDED (not applied). */
    private function configSnapshot(): array
    {
        return [
            'classify' => config('classify'),
            'services.openrouter.classify_model' => config('services.openrouter.classify_model'),
            'services.openrouter.classify_model_tier1' => config('services.openrouter.classify_model_tier1'),
        ];
    }
}
