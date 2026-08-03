<?php

namespace Tests\Feature\Gpu;

use App\Models\FinetuneAdapter;
use App\Models\GpuServer;
use App\Services\Gpu\GpuOrchestrator;
use App\Services\Gpu\GpuServerManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

// The server-slot state machine advances correctly against a FAKE orchestrator (no Nebius,
// no rental): provision → booting → deploy → serving; the switch is an instant flag flip;
// destroy resets; the "forgot" safety only kills the active idle slot and any over-ceiling
// slot — never a warm not-yet-active candidate.
class GpuServerManagerTest extends TestCase
{
    use RefreshDatabase;

    private function manager(FakeOrchestrator $fake): GpuServerManager
    {
        $this->app->instance(GpuOrchestrator::class, $fake);

        return $this->app->make(GpuServerManager::class);
    }

    private function slot(string $slot): GpuServer
    {
        return GpuServer::where('slot', $slot)->firstOrFail();
    }

    public function test_provision_refuses_without_a_golden_snapshot(): void
    {
        config()->set('gpu.nebius.golden_snapshot_id', null);
        $adapter = FinetuneAdapter::create(['version' => 'v1', 'vps_path' => '/vps/v1.tar']);

        $this->expectException(RuntimeException::class);
        $this->manager(new FakeOrchestrator)->provisionServing($this->slot('A'), $adapter);
    }

    public function test_full_serving_lifecycle_reaches_serving(): void
    {
        config()->set('gpu.nebius.golden_snapshot_id', 'computedisksnapshot-test');
        $adapter = FinetuneAdapter::create(['version' => 'v1', 'vps_path' => '/vps/v1.tar']);
        $fake = new FakeOrchestrator;
        $mgr = $this->manager($fake);

        $mgr->provisionServing($this->slot('A'), $adapter);
        $a = $this->slot('A');
        $this->assertSame(GpuServer::STATUS_BOOTING, $a->status);
        $this->assertSame('computeinstance-fake', $a->instance_id);

        // Booting: ssh reachable → deploy the adapter.
        $fake->statusResponse = ['ok' => true, 'ip' => '10.0.0.5', 'ssh_ok' => true, 'vllm_ready' => false, 'models' => []];
        $mgr->poll($this->slot('A'));
        $this->assertSame(GpuServer::STATUS_DEPLOYING_ADAPTER, $this->slot('A')->status);
        $this->assertTrue($fake->deployed);

        // Deploying: vLLM up with xif → serving.
        $fake->statusResponse = ['ok' => true, 'ip' => '10.0.0.5', 'ssh_ok' => true, 'vllm_ready' => true, 'models' => ['base', 'xif']];
        $mgr->poll($this->slot('A'));
        $a = $this->slot('A');
        $this->assertSame(GpuServer::STATUS_SERVING, $a->status);
        $this->assertSame('http://10.0.0.5:8000/v1', $a->base_url);
        $this->assertTrue($a->isServing());
    }

    public function test_serve_base_turns_on_reaches_serving_and_auto_activates(): void
    {
        config()->set('gpu.nebius.golden_snapshot_id', 'computedisksnapshot-test');
        $fake = new FakeOrchestrator;
        $mgr = $this->manager($fake);

        $mgr->provisionBaseServing($this->slot('A'));
        $a = $this->slot('A');
        $this->assertSame(GpuServer::STATUS_BOOTING, $a->status);
        $this->assertNull($a->served_adapter_id); // base only

        // SSH up → start vLLM with the base model (no adapter).
        $fake->statusResponse = ['ok' => true, 'ip' => '10.0.0.7', 'ssh_ok' => true, 'vllm_ready' => false, 'models' => []];
        $mgr->poll($this->slot('A'));
        $this->assertTrue($fake->servedBase);
        $this->assertFalse($fake->deployed); // no adapter deploy
        $this->assertSame(GpuServer::STATUS_DEPLOYING_ADAPTER, $this->slot('A')->status);

        // vLLM ready with `base` → serving, and auto-active since nothing else is active.
        $fake->statusResponse = ['ok' => true, 'ip' => '10.0.0.7', 'ssh_ok' => true, 'vllm_ready' => true, 'models' => ['base']];
        $mgr->poll($this->slot('A'));
        $a = $this->slot('A');
        $this->assertSame(GpuServer::STATUS_SERVING, $a->status);
        $this->assertTrue($a->is_active);         // requests now route here
        $this->assertTrue($a->isServing());
    }

    public function test_serve_base_does_not_steal_active_from_an_existing_server(): void
    {
        config()->set('gpu.nebius.golden_snapshot_id', 'computedisksnapshot-test');
        $fake = new FakeOrchestrator;
        $mgr = $this->manager($fake);
        // B is already the active server.
        $this->slot('B')->update(['is_active' => true, 'status' => GpuServer::STATUS_SERVING, 'base_url' => 'http://b:8000/v1']);

        $mgr->provisionBaseServing($this->slot('A'));
        $this->slot('A')->update(['ip' => '10.0.0.7']);
        $fake->statusResponse = ['ok' => true, 'ip' => '10.0.0.7', 'ssh_ok' => true, 'vllm_ready' => true, 'models' => ['base']];
        // Force through booting → serving.
        $mgr->poll($this->slot('A')); // booting → serveBase → deploying
        $mgr->poll($this->slot('A')); // deploying → serving
        $a = $this->slot('A');
        $this->assertSame(GpuServer::STATUS_SERVING, $a->status);
        $this->assertFalse($a->is_active);        // B keeps serving; A awaits an explicit switch
        $this->assertTrue($this->slot('B')->is_active);
    }

    public function test_switch_flips_active_and_returns_previous(): void
    {
        $fake = new FakeOrchestrator;
        $mgr = $this->manager($fake);

        // A is the current active serving slot.
        $this->slot('A')->update(['is_active' => true, 'status' => GpuServer::STATUS_SERVING, 'base_url' => 'http://a:8000/v1']);
        // B is a freshly-trained, warm candidate.
        $this->slot('B')->update(['status' => GpuServer::STATUS_SERVING, 'base_url' => 'http://b:8000/v1']);

        $previous = $mgr->switchActiveTo($this->slot('B'));

        $this->assertNotNull($previous);
        $this->assertSame('A', $previous->slot);
        $this->assertTrue($this->slot('B')->fresh()->is_active);
        $this->assertFalse($this->slot('A')->fresh()->is_active);
    }

    public function test_switch_rejects_a_slot_that_is_not_serving(): void
    {
        $this->slot('B')->update(['status' => GpuServer::STATUS_BOOTING]);
        $this->expectException(RuntimeException::class);
        $this->manager(new FakeOrchestrator)->switchActiveTo($this->slot('B'));
    }

    public function test_destroy_resets_slot_and_calls_orchestrator(): void
    {
        $fake = new FakeOrchestrator;
        $this->slot('A')->update(['status' => GpuServer::STATUS_SERVING, 'instance_id' => 'computeinstance-x', 'is_active' => true, 'ip' => '1.2.3.4']);

        $this->manager($fake)->destroy($this->slot('A'));

        $a = $this->slot('A');
        $this->assertSame(GpuServer::STATUS_OFF, $a->status);
        $this->assertNull($a->instance_id);
        $this->assertFalse($a->is_active);
        $this->assertSame('computeinstance-x', $fake->destroyedId);
    }

    public function test_safety_destroys_idle_active_and_over_ceiling_but_spares_candidate(): void
    {
        config()->set('gpu.autostop_idle_minutes', 120);
        $fake = new FakeOrchestrator;
        $mgr = $this->manager($fake);

        // Active, serving, idle 3h → destroyed.
        $this->slot('A')->update([
            'status' => GpuServer::STATUS_SERVING, 'is_active' => true, 'instance_id' => 'computeinstance-a',
            'last_request_at' => now()->subHours(3), 'hard_deadline_at' => now()->addHours(10),
        ]);
        // Warm candidate (NOT active), also "idle" but must be spared.
        $this->slot('B')->update([
            'status' => GpuServer::STATUS_SERVING, 'is_active' => false, 'instance_id' => 'computeinstance-b',
            'last_request_at' => now()->subHours(3), 'hard_deadline_at' => now()->addHours(10),
        ]);

        $mgr->enforceSafety();

        $this->assertSame(GpuServer::STATUS_OFF, $this->slot('A')->status);   // idle active → gone
        $this->assertSame(GpuServer::STATUS_SERVING, $this->slot('B')->status); // candidate spared
    }

    public function test_safety_destroys_any_slot_past_hard_ceiling(): void
    {
        $mgr = $this->manager(new FakeOrchestrator);
        $this->slot('B')->update([
            'status' => GpuServer::STATUS_TRAINING, 'instance_id' => 'computeinstance-b',
            'hard_deadline_at' => now()->subMinute(),
        ]);

        $mgr->enforceSafety();

        $this->assertSame(GpuServer::STATUS_OFF, $this->slot('B')->status);
    }
}
