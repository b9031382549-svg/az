<?php

namespace App\Livewire;

use App\Jobs\GpuActionJob;
use App\Models\FinetuneAdapter;
use App\Models\FinetuneRun;
use App\Models\GpuServer;
use App\Services\Gpu\GpuServerManager;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

// GPU servers tab — the A/B zero-downtime retrain control room. Buttons dispatch bounded
// GpuActionJobs (provision / train / destroy / tick); the SWITCH is an instant in-request
// flag flip. The page just reads DB state (wire:poll refreshes it) — all slow work runs on
// the queue and shows up as status/progress here.
#[Layout('components.app-layout', ['title' => 'GPU servers'])]
class GpuServers extends Component
{
    /** Adapter chosen in the "serve an existing adapter" picker, per slot id. */
    public array $serveAdapter = [];

    public function provisionServing(int $serverId): void
    {
        $adapterId = (int) ($this->serveAdapter[$serverId] ?? 0);
        if (! $adapterId) {
            $this->addError('gpu', __('Pick an adapter to serve.'));

            return;
        }
        GpuActionJob::dispatch('provision-serving', $serverId, $adapterId);
        Audit::log('gpu.provision_serving', ['server' => $serverId, 'adapter' => $adapterId]);
    }

    public function startFinetune(int $serverId): void
    {
        GpuActionJob::dispatch('start-finetune', $serverId);
        Audit::log('gpu.start_finetune', ['server' => $serverId]);
    }

    /** "Just turn it on": provision a slot serving the base model (no training/adapter). */
    public function serveBase(int $serverId): void
    {
        GpuActionJob::dispatch('serve-base', $serverId);
        Audit::log('gpu.serve_base', ['server' => $serverId]);
    }

    /** Zero-downtime switch: flip the active pointer now, tear the old slot down after. */
    public function switchTo(int $serverId, GpuServerManager $servers): void
    {
        $server = GpuServer::find($serverId);
        if (! $server) {
            return;
        }
        try {
            $previous = $servers->switchActiveTo($server);
        } catch (Throwable $e) {
            $this->addError('gpu', $e->getMessage());

            return;
        }
        if ($previous) {
            GpuActionJob::dispatch('destroy', $previous->id); // free the old slot in the background
        }
        Audit::log('gpu.switch', ['to' => $serverId, 'from' => $previous?->id]);
    }

    public function destroyServer(int $serverId): void
    {
        GpuActionJob::dispatch('destroy', $serverId);
        Audit::log('gpu.destroy', ['server' => $serverId]);
    }

    /** Kick the state machine one pass (until the scheduler drives it). */
    public function tick(): void
    {
        GpuActionJob::dispatch('tick');
    }

    /**
     * Called by wire:poll while the page is open. Re-renders the DB view every tick and,
     * ONLY while something is mid-flight, drives the state machine forward — single-flight
     * (Cache::add is atomic; the tick job clears the flag when done) so a slow orchestrator
     * pass never stacks behind the poll. This is what makes "turn on" self-advance to
     * `serving` without the user clicking Refresh, until the scheduler takes over.
     */
    public function pollState(): void
    {
        if ($this->working() && Cache::add('gpu:tick:queued', true, now()->addSeconds(120))) {
            GpuActionJob::dispatch('tick');
        }
    }

    /** Anything transient on either slot or in a run → the page keeps auto-advancing. */
    private function working(): bool
    {
        return GpuServer::query()->whereIn('status', [
            GpuServer::STATUS_PROVISIONING,
            GpuServer::STATUS_BOOTING,
            GpuServer::STATUS_DEPLOYING_ADAPTER,
        ])->exists()
            || FinetuneRun::query()->whereNotIn('status', [FinetuneRun::STATUS_DONE, FinetuneRun::STATUS_FAILED])->exists();
    }

    public function render()
    {
        $servers = GpuServer::with('adapter')->orderBy('slot')->get();
        $active = $servers->firstWhere('is_active', true);

        return view('livewire.gpu-servers', [
            'servers' => $servers,
            'active' => $active,
            'adapters' => FinetuneAdapter::latest()->limit(20)->get(),
            'runs' => FinetuneRun::with(['server', 'adapter'])->latest()->limit(10)->get(),
            'goldenReady' => filled(config('gpu.nebius.golden_snapshot_id')),
            'working' => $this->working(),
        ]);
    }
}
