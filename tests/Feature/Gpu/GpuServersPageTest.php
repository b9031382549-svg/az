<?php

namespace Tests\Feature\Gpu;

use App\Jobs\GpuActionJob;
use App\Livewire\GpuServers;
use App\Models\FinetuneAdapter;
use App\Models\GpuServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

// The GPU servers page renders both slots, tells the user WHERE traffic goes (Token Factory
// fallback when idle), and its buttons dispatch the bounded control jobs. The switch is the
// only synchronous action (an instant flag flip) and it also queues the old slot's teardown.
class GpuServersPageTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        $this->actingAs(User::factory()->create());
    }

    public function test_page_renders_both_slots_and_the_fallback_banner(): void
    {
        $this->login();

        Livewire::test(GpuServers::class)
            ->assertOk()
            ->assertSee('Slot')
            ->assertSee('Token Factory'); // no active server → fallback is labelled
    }

    public function test_poll_drives_a_throttled_tick_only_while_working(): void
    {
        Bus::fake();
        Cache::flush();
        $this->login();

        // Idle (both slots off) → the page polls but dispatches nothing.
        Livewire::test(GpuServers::class)->call('pollState');
        Bus::assertNotDispatched(GpuActionJob::class);

        // A slot mid-flight → one tick dispatched, then throttled (single-flight) until the
        // tick job clears the flag.
        GpuServer::where('slot', 'A')->update(['status' => GpuServer::STATUS_BOOTING]);
        Livewire::test(GpuServers::class)->call('pollState');
        Livewire::test(GpuServers::class)->call('pollState');
        Bus::assertDispatchedTimes(GpuActionJob::class, 1);
    }

    public function test_turn_on_base_dispatches_a_serve_base_job(): void
    {
        Bus::fake();
        $this->login();
        config()->set('gpu.nebius.golden_snapshot_id', 'computedisksnapshot-test');
        $slotA = GpuServer::where('slot', 'A')->firstOrFail();

        Livewire::test(GpuServers::class)->call('serveBase', $slotA->id);

        Bus::assertDispatched(GpuActionJob::class, fn (GpuActionJob $j) => $j->action === 'serve-base' && $j->serverId === $slotA->id);
    }

    public function test_start_training_dispatches_a_bounded_job(): void
    {
        Bus::fake();
        $this->login();
        config()->set('gpu.nebius.golden_snapshot_id', 'computedisksnapshot-test');
        $slotB = GpuServer::where('slot', 'B')->firstOrFail();

        Livewire::test(GpuServers::class)->call('startFinetune', $slotB->id);

        Bus::assertDispatched(GpuActionJob::class, fn (GpuActionJob $j) => $j->action === 'start-finetune' && $j->serverId === $slotB->id);
    }

    public function test_switch_flips_active_now_and_queues_old_slot_teardown(): void
    {
        Bus::fake();
        $this->login();
        $a = GpuServer::where('slot', 'A')->firstOrFail();
        $b = GpuServer::where('slot', 'B')->firstOrFail();
        $a->update(['is_active' => true, 'status' => GpuServer::STATUS_SERVING, 'base_url' => 'http://a:8000/v1', 'instance_id' => 'computeinstance-a']);
        $b->update(['status' => GpuServer::STATUS_READY_TO_SWITCH, 'base_url' => 'http://b:8000/v1']);
        // ready_to_switch must be servable for the switch — put it in serving for the guard.
        $b->update(['status' => GpuServer::STATUS_SERVING]);

        Livewire::test(GpuServers::class)->call('switchTo', $b->id);

        $this->assertTrue($b->fresh()->is_active);
        $this->assertFalse($a->fresh()->is_active);
        Bus::assertDispatched(GpuActionJob::class, fn (GpuActionJob $j) => $j->action === 'destroy' && $j->serverId === $a->id);
    }

    public function test_provision_serving_requires_an_adapter_selection(): void
    {
        Bus::fake();
        $this->login();
        config()->set('gpu.nebius.golden_snapshot_id', 'computedisksnapshot-test');
        FinetuneAdapter::create(['version' => 'v1', 'vps_path' => '/vps/v1.tar']);
        $a = GpuServer::where('slot', 'A')->firstOrFail();

        // No adapter picked → validation error, no job.
        Livewire::test(GpuServers::class)
            ->call('provisionServing', $a->id)
            ->assertHasErrors('gpu');
        Bus::assertNotDispatched(GpuActionJob::class);
    }
}
