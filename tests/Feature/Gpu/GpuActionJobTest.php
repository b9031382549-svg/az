<?php

namespace Tests\Feature\Gpu;

use App\Jobs\GpuActionJob;
use App\Models\AnswerCache;
use App\Models\FinetuneRun;
use App\Models\GpuServer;
use App\Services\Gpu\FinetuneManager;
use App\Services\Gpu\GpuCoordinator;
use App\Services\Gpu\GpuOrchestrator;
use App\Services\Gpu\GpuServerManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GpuActionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_finetune_threads_the_dataset_id_into_the_run(): void
    {
        config()->set('gpu.nebius.golden_snapshot_id', 'computedisksnapshot-test');
        AnswerCache::create(['test_dataset_id' => 0, 'source' => 'gold', 'name' => 'Prod Item',
            'name_key' => AnswerCache::keyFor('Prod Item'), 'heading' => '8471', 'is_service' => false]);
        AnswerCache::create(['test_dataset_id' => 8, 'source' => 'gold', 'name' => 'Dataset 8 Item',
            'name_key' => AnswerCache::keyFor('Dataset 8 Item'), 'heading' => '8471', 'is_service' => false]);
        $this->app->instance(GpuOrchestrator::class, new FakeOrchestrator);
        $slot = GpuServer::where('slot', 'B')->firstOrFail();

        (new GpuActionJob('start-finetune', $slot->id, datasetId: 8))->handle(
            $this->app->make(GpuServerManager::class),
            $this->app->make(FinetuneManager::class),
            $this->app->make(GpuCoordinator::class),
        );

        $run = FinetuneRun::latest('id')->firstOrFail();
        $this->assertSame(8, $run->source_dataset_id);
        $corpus = collect(file((string) $run->corpus_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertCount(1, $corpus);
        @unlink((string) $run->corpus_path);
    }

    public function test_start_finetune_without_a_dataset_id_trains_on_production(): void
    {
        config()->set('gpu.nebius.golden_snapshot_id', 'computedisksnapshot-test');
        AnswerCache::create(['test_dataset_id' => 0, 'source' => 'gold', 'name' => 'Prod Item',
            'name_key' => AnswerCache::keyFor('Prod Item'), 'heading' => '8471', 'is_service' => false]);
        $this->app->instance(GpuOrchestrator::class, new FakeOrchestrator);
        $slot = GpuServer::where('slot', 'B')->firstOrFail();

        (new GpuActionJob('start-finetune', $slot->id))->handle(
            $this->app->make(GpuServerManager::class),
            $this->app->make(FinetuneManager::class),
            $this->app->make(GpuCoordinator::class),
        );

        $run = FinetuneRun::latest('id')->firstOrFail();
        $this->assertNull($run->source_dataset_id);
        @unlink((string) $run->corpus_path);
    }
}
