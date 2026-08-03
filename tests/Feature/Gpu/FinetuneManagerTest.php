<?php

namespace Tests\Feature\Gpu;

use App\Models\FinetuneAdapter;
use App\Models\FinetuneRun;
use App\Models\GpuServer;
use App\Services\Gpu\FinetuneManager;
use App\Services\Gpu\GpuOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The retrain cycle drives itself across ticks against the fake orchestrator, with no eval
// dataset configured (so no TestRun is needed): start → training → fetch (adapter
// registered on the VPS path) → deploy on the same slot → ready_to_switch. Proves the
// training slot BECOMES the new serving candidate — the basis of the zero-downtime switch.
class FinetuneManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_cycle_runs_to_ready_to_switch_and_registers_the_adapter(): void
    {
        config()->set('gpu.nebius.golden_snapshot_id', 'computedisksnapshot-test');
        config()->set('gpu.eval_dataset_id', null); // skip the eval-gate for this unit
        $fake = new FakeOrchestrator;
        $this->app->instance(GpuOrchestrator::class, $fake);
        $manager = $this->app->make(FinetuneManager::class);

        $slot = GpuServer::where('slot', 'B')->firstOrFail();
        $run = $manager->start($slot);

        $this->assertSame(FinetuneRun::STATUS_PENDING, $run->fresh()->status);
        $this->assertSame(GpuServer::STATUS_BOOTING, $slot->fresh()->status);
        $this->assertNotNull($run->fresh()->corpus_path);

        // Pending → training once SSH is up.
        $fake->statusResponse = ['ok' => true, 'ip' => '10.0.0.9', 'ssh_ok' => true, 'vllm_ready' => false, 'models' => []];
        $manager->poll($run->fresh());
        $this->assertTrue($fake->trainingStarted);
        $this->assertSame(FinetuneRun::STATUS_TRAINING, $run->fresh()->status);

        // Training progress mirrors the heartbeat.
        $fake->trainingStatusResponse = ['ok' => true, 'state' => 'training', 'step' => 40, 'total_steps' => 100, 'loss' => 0.21, 'eta_seconds' => 300];
        $manager->poll($run->fresh());
        $this->assertSame(40, $run->fresh()->step);
        $this->assertSame(40, $run->fresh()->progressPct());

        // Training done → one step moves to fetching, the next does the fetch + deploy.
        $fake->trainingStatusResponse = ['ok' => true, 'state' => 'done', 'step' => 100, 'total_steps' => 100, 'loss' => 0.09, 'eta_seconds' => 0];
        $manager->poll($run->fresh());
        $this->assertSame(FinetuneRun::STATUS_FETCHING, $run->fresh()->status);
        $manager->poll($run->fresh());
        $this->assertSame(FinetuneRun::STATUS_EVALUATING, $run->fresh()->status);
        $this->assertTrue($fake->deployed);
        $adapter = FinetuneAdapter::firstWhere('trained_from_run_id', $run->id);
        $this->assertNotNull($adapter);
        $this->assertSame($adapter->id, $run->fresh()->adapter_id);
        $this->assertSame(GpuServer::STATUS_DEPLOYING_ADAPTER, $slot->fresh()->status);

        // Evaluating: slot finishes deploying → serving → run done, slot ready to switch.
        $fake->statusResponse = ['ok' => true, 'ip' => '10.0.0.9', 'ssh_ok' => true, 'vllm_ready' => true, 'models' => ['base', 'xif']];
        $manager->poll($run->fresh());   // advances deploy → serving
        $manager->poll($run->fresh());   // serving + no eval dataset → finish
        $this->assertSame(FinetuneRun::STATUS_DONE, $run->fresh()->status);
        $this->assertSame(GpuServer::STATUS_READY_TO_SWITCH, $slot->fresh()->status);
        $this->assertSame($adapter->id, $slot->fresh()->served_adapter_id);
    }
}
