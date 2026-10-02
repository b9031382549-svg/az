<?php

namespace App\Services\Testing;

use App\Jobs\ClassifyTestItemMechanismJob;
use App\Jobs\ScoreRunJob;
use App\Jobs\SortTestItemsJob;
use App\Models\ClassificationItem;
use App\Models\TestDataset;
use App\Models\TestRun;
use App\Services\Classify\AnswerCacheService;
use App\Services\Classify\Sorter;
use App\Services\Classify\TrashFilter;
use Illuminate\Support\Facades\Bus;

/**
 * Launches a dataset test run as the PRODUCTION pipeline, in prod's order
 * (ClassificationQueue::dispatch): only-digits → memory (the dataset's own, when the run
 * uses it) → the trash rules → the sorter (SortTestItemsJob: sure trash is settled there)
 * → one short mechanism job per (item, mechanism) on the normal 'default' queue,
 * reconciled against the run's chosen mechanism set. Every row takes this path, those that
 * carry only a kind (TRASH / GOOD / SERVICE) too. No per-run config override and no
 * dedicated queue — the run reads the live prod config exactly like a real classification,
 * so nothing can drift or "leak".
 *
 * `config` is snapshotted only as a RECORD (what models/flags this run used) for reading
 * an A/B comparison later — it is never applied.
 */
class TestRunner
{
    public function __construct(
        private readonly AnswerCacheService $cache,
        private readonly Sorter $sorter,
        private readonly TrashFilter $trash,
    ) {}

    /**
     * @param  array{enabled:array<int,string>, shadow?:array<int,string>, cache?:bool, search?:bool}  $mechanisms
     * @param  array{model?:string, expand_model?:string, base_url?:string, api_key?:string}  $override
     *                                                                                                   optional external model endpoint (e.g. a fine-tuned
     *                                                                                                   model on a rented GPU). Empty → mirror prod. Unlike `config`
     *                                                                                                   (a record only), this IS applied by the run's mechanism jobs
     *                                                                                                   via EndpointOverride.
     */
    public function launch(TestDataset $dataset, string $description, array $mechanisms, array $override = []): TestRun
    {
        $run = new TestRun;
        $run->test_dataset_id = $dataset->id;
        $run->description = trim($description);
        $run->mechanisms = $this->normalizeMechanisms($mechanisms);
        $run->config = $this->configSnapshot($override);
        $run->model_override = trim((string) ($override['model'] ?? '')) ?: null;
        $run->expand_model_override = trim((string) ($override['expand_model'] ?? '')) ?: null;
        $run->endpoint_base_url = trim((string) ($override['base_url'] ?? '')) ?: null;
        $run->endpoint_api_key = trim((string) ($override['api_key'] ?? '')) ?: null;
        $run->status = 'running';
        $run->total = $dataset->scorableRows()->count();
        $run->started_at = now();
        $run->save();
        $run->update(['batch' => TestRun::batchKey($run->id)]);

        $useCache = $run->mechanisms['cache'];
        $enabled = $run->mechanisms['enabled'];

        $all = [];   // every item gets the sorter's verdict (the run's "Sorter" column)
        $flow = [];  // those memory and the rules leave — prod's next step is the sorter
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
            $all[] = $item->id;

            // Prod's order: only digits beats everything, then memory (dataset-scoped
            // short-circuit when the run uses it), then the trash rules. Each is terminal.
            if ($this->trash->apply($item, absoluteOnly: true)
                || ($useCache && $this->cache->apply($item, $run->test_dataset_id))
                || $this->trash->apply($item)) {
                continue;
            }
            $flow[] = $item->id;
        }

        $jobs = [];
        if ($this->sorter->enabled()) {
            foreach (array_chunk($all, SortTestItemsJob::SIZE) as $chunk) {
                $jobs[] = new SortTestItemsJob($chunk, array_values(array_intersect($chunk, $flow)));
            }
        } else {
            $jobs = ClassifyTestItemMechanismJob::for($flow, $enabled); // no sorter: straight to the AI, as prod
        }

        if ($jobs === []) {
            ScoreRunJob::dispatch($run->id); // memory / the rules answered everything (or nothing to run) — score now

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

    /**
     * Whole classify subtree + the two rerank model ids — RECORDED (not applied).
     * When an endpoint override is set, the snapshot reflects what the run ACTUALLY
     * uses (its jobs apply it), so the A/B history reads truthfully instead of showing
     * the prod baseline.
     *
     * @param  array{model?:string, expand_model?:string, base_url?:string, api_key?:string}  $override
     */
    private function configSnapshot(array $override = []): array
    {
        $snap = [
            'classify' => config('classify'),
            'services.openrouter.classify_model' => config('services.openrouter.classify_model'),
            'services.openrouter.classify_model_tier1' => config('services.openrouter.classify_model_tier1'),
        ];

        $model = trim((string) ($override['model'] ?? ''));
        if ($model !== '') {
            if (! str_starts_with($model, 'nebius:')) {
                $model = 'nebius:'.$model;
            }
            $snap['services.openrouter.classify_model'] = $model;
            $snap['services.openrouter.classify_model_tier1'] = $model;
            $snap['classify']['broker']['model'] = $model;
            $snap['classify']['broker']['brief_model'] = $model;
            $snap['classify']['broker']['fact_model'] = $model;
            $snap['classify']['direct']['model'] = $model;
            $snap['classify']['direct']['granularity'] = 'heading';
            $snap['classify']['broker']['answer_granularity'] = 'heading';
        }

        $expand = trim((string) ($override['expand_model'] ?? ''));
        if ($expand !== '') {
            $snap['classify']['expand_model'] = str_starts_with($expand, 'nebius:') ? $expand : 'nebius:'.$expand;
        }

        return $snap;
    }
}
