<?php

namespace App\Services\Gpu;

use App\Models\FinetuneAdapter;
use App\Models\GpuServer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Server-slot state machine. Every method is bounded (one orchestrator call + a DB write);
 * the long waits (boot, model load) are crossed by REPEATED poll() calls from gpu:tick, so
 * no PHP job ever blocks for training-length time. The zero-downtime switch is a single
 * in-transaction flag flip — the new slot is already serving before it becomes active, and
 * the old one is destroyed only afterwards.
 */
class GpuServerManager
{
    public function __construct(private readonly GpuOrchestrator $orchestrator) {}

    /**
     * Bring a slot up to SERVE an existing adapter (e.g. re-serving after a teardown). The
     * first-ever adapter comes from a training run, not this. Provisions from the golden
     * image, then poll() deploys the adapter and flips to `serving`.
     */
    public function provisionServing(GpuServer $server, FinetuneAdapter $adapter): void
    {
        $this->assertProvisionable($server);

        $server->fill([
            'role' => 'serving',
            'status' => GpuServer::STATUS_PROVISIONING,
            'served_adapter_id' => $adapter->id,
            'api_key' => 'sk-'.Str::random(40),
            'hard_deadline_at' => now()->addHours((int) config('gpu.hard_ceiling_hours')),
            'status_detail' => 'Provisioning to serve '.$adapter->version,
        ])->save();

        $this->provision($server);
    }

    /**
     * The simplest "just turn it on": bring a slot up serving ONLY the base model — no
     * training, no adapter. Exercises provisioning + request routing end to end; once it
     * reaches `serving` and (if nothing else is active) becomes active, gpu:base requests
     * flow to it. Still rents a GPU (the 70B needs it) — serving is not free.
     */
    public function provisionBaseServing(GpuServer $server): void
    {
        $this->assertProvisionable($server);

        $server->fill([
            'role' => 'serving',
            'status' => GpuServer::STATUS_PROVISIONING,
            'served_adapter_id' => null,  // base only
            'api_key' => 'sk-'.Str::random(40),
            'hard_deadline_at' => now()->addHours((int) config('gpu.hard_ceiling_hours')),
            'status_detail' => 'Provisioning to serve the base model (no adapter)',
        ])->save();

        $this->provision($server);
    }

    /** Bring a slot up as a TRAINING worker (no adapter yet); FinetuneManager drives it. */
    public function provisionTraining(GpuServer $server): void
    {
        $this->assertProvisionable($server);

        $server->fill([
            'role' => 'training',
            'status' => GpuServer::STATUS_PROVISIONING,
            'api_key' => 'sk-'.Str::random(40),
            'hard_deadline_at' => now()->addHours((int) config('gpu.hard_ceiling_hours')),
            'status_detail' => 'Provisioning to train',
        ])->save();

        $this->provision($server);
    }

    /**
     * Advance a serving-path slot one step: provisioning/booting → deploy adapter →
     * serving. Errors park the slot in `error` with the message (never throws to the tick).
     */
    public function poll(GpuServer $server): void
    {
        if (! $server->instance_id) {
            return;
        }
        try {
            $st = $this->orchestrator->status($server->instance_id, $server->api_key);
            if (! empty($st['ip'])) {
                $server->ip = $st['ip'];
                $server->base_url = 'http://'.$st['ip'].':8000/v1';
            }

            match ($server->status) {
                GpuServer::STATUS_PROVISIONING, GpuServer::STATUS_BOOTING => $this->onBooting($server, $st),
                GpuServer::STATUS_DEPLOYING_ADAPTER => $this->onDeploying($server, $st),
                default => null,
            };
            $server->save();
        } catch (Throwable $e) {
            $this->park($server, $e);
        }
    }

    /**
     * The zero-downtime switch: make $server the active endpoint. It must already be
     * serving (warm). Returns the slot that WAS active (the caller destroys it after).
     */
    public function switchActiveTo(GpuServer $server): ?GpuServer
    {
        // A valid target is warm (vLLM up, base_url set) and either already `serving` or a
        // freshly-trained `ready_to_switch` candidate. The latter is the WHOLE point of a
        // retrain — so accept it, and flip it to `serving` below (its status must become
        // `serving` or isServing()/the resolver won't route traffic to it once active).
        $switchable = [GpuServer::STATUS_SERVING, GpuServer::STATUS_READY_TO_SWITCH];
        if (! in_array($server->status, $switchable, true) || blank($server->base_url)) {
            throw new RuntimeException("Slot {$server->slot} is not serving yet — cannot switch to it.");
        }
        $previous = GpuServer::active();

        DB::transaction(function () use ($server) {
            GpuServer::query()->where('is_active', true)->update(['is_active' => false]);
            $server->is_active = true;
            $server->role = 'serving';
            $server->status = GpuServer::STATUS_SERVING;
            $server->last_request_at = now();
            $server->save();
        });

        return ($previous && $previous->id !== $server->id) ? $previous : null;
    }

    /** Destroy the VM and reset the slot to off. Idempotent; keeps the adapter + image. */
    public function destroy(GpuServer $server): void
    {
        $warning = null;
        if ($server->instance_id) {
            try {
                $this->orchestrator->destroy($server->instance_id);
            } catch (Throwable $e) {
                // Keep going — a stuck instance must not pin the slot — but surface a GENUINE
                // failure. (The orchestrator already verifies a transient-error delete actually
                // failed before throwing, so this only fires when the VM really survived.)
                $warning = 'Destroy warning: '.$e->getMessage();
            }
        }
        $server->fill([
            'role' => null,
            'status' => GpuServer::STATUS_OFF,
            'is_active' => false,
            'instance_id' => null,
            'ip' => null,
            'base_url' => null,
            'api_key' => null,
            'served_adapter_id' => null,
            'current_run_id' => null,
            'hard_deadline_at' => null,
            // Cleared on a clean teardown so a stale message never lingers on an off slot;
            // only a real destroy failure leaves a warning behind.
            'status_detail' => $warning,
        ])->save();
    }

    /**
     * "Forgot" safety (Nebius-only): destroy the ACTIVE serving slot after a genuine idle
     * gap, and ANY live slot past its hard ceiling. The idle rule never touches a not-yet-
     * active candidate (it's awaiting a human switch, has no traffic by definition).
     */
    public function enforceSafety(): void
    {
        $idleMinutes = (int) config('gpu.autostop_idle_minutes');

        foreach (GpuServer::query()->whereNotIn('status', [GpuServer::STATUS_OFF, GpuServer::STATUS_ERROR])->get() as $server) {
            if ($server->hard_deadline_at && $server->hard_deadline_at->isPast()) {
                $server->status_detail = 'Hard ceiling reached — auto-destroyed.';
                $this->destroy($server);

                continue;
            }
            $idle = $server->is_active
                && $server->status === GpuServer::STATUS_SERVING
                && $server->last_request_at
                && $server->last_request_at->lt(now()->subMinutes($idleMinutes));
            if ($idle) {
                $server->status_detail = "Idle > {$idleMinutes}m — auto-destroyed (falls back to Token Factory).";
                $this->destroy($server);
            }
        }
    }

    private function onBooting(GpuServer $server, array $st): void
    {
        $server->status = GpuServer::STATUS_BOOTING;
        if (! ($st['ssh_ok'] ?? false)) {
            // A snapshot-boot instance auto-starts after create and comes up on its own —
            // do NOT issue our own `start` here: a competing start op aborts the in-flight
            // one and wedges the instance in STARTING forever. Just wait for SSH.
            $bootingFor = $this->bootingElapsedMinutes($server);
            // Nebius went STARTING then STOPPED without ever reaching RUNNING → it could not
            // place the GPU (H200 capacity). Surface it instead of hanging until the ceiling.
            if (($st['nebius_status'] ?? null) === 'STOPPED' && $bootingFor > 3) {
                throw new RuntimeException('Instance stopped during boot — Nebius could not place the GPU (capacity). Try again later or a different GPU type.');
            }
            // Never reached SSH within a generous boot window → give up rather than hang.
            if ($bootingFor > 15) {
                throw new RuntimeException("Boot timed out after {$bootingFor}m (nebius=".($st['nebius_status'] ?? '?').') — likely GPU capacity. Try again later.');
            }
            $server->status_detail = 'Waiting for the instance to boot ('.($st['nebius_status'] ?? '…').", {$bootingFor}m)";

            return;
        }
        if ($server->served_adapter_id) {
            $adapter = $server->adapter;
            if ($adapter && $adapter->vps_path) {
                $this->orchestrator->deployAdapter($server->ip, $server->api_key, $adapter->vps_path);
                $server->status = GpuServer::STATUS_DEPLOYING_ADAPTER;
                $server->status_detail = 'Deploying '.$adapter->version.' + starting vLLM (cold load ~15m)';
            }

            return;
        }
        // No adapter → serve the base model only (the "just turn it on" path).
        $this->orchestrator->serveBase($server->ip, $server->api_key);
        $server->status = GpuServer::STATUS_DEPLOYING_ADAPTER;
        $server->status_detail = 'Starting vLLM with the base model (cold load ~15m)';
    }

    private function onDeploying(GpuServer $server, array $st): void
    {
        $models = (array) ($st['models'] ?? []);
        // Adapter serve waits for `xif`; base serve waits for `base`.
        $expected = $server->served_adapter_id ? 'xif' : 'base';
        if (($st['vllm_ready'] ?? false) && in_array($expected, $models, true)) {
            $server->status = GpuServer::STATUS_SERVING;
            $server->last_request_at = now();
            $server->status_detail = 'Serving '.implode(', ', $models);
            // Auto-activate a MANUALLY-provisioned serve slot (not a training-produced one,
            // which must pass the eval-gate + explicit switch) when nothing else is active —
            // so "turn it on" also routes requests here with no extra click.
            if ($server->current_run_id === null && GpuServer::active() === null) {
                $server->is_active = true;
            }
        }
    }

    /** Minutes since this slot was provisioned (recovered from the ceiling-anchored deadline). */
    private function bootingElapsedMinutes(GpuServer $server): int
    {
        if (! $server->hard_deadline_at) {
            return 0;
        }
        $provisionedAt = $server->hard_deadline_at->copy()->subHours((int) config('gpu.hard_ceiling_hours'));

        return max(0, (int) $provisionedAt->diffInMinutes(now()));
    }

    private function assertProvisionable(GpuServer $server): void
    {
        if (empty(config('gpu.nebius.golden_snapshot_id'))) {
            throw new RuntimeException('No golden snapshot configured (NEBIUS_GOLDEN_SNAPSHOT_ID) — bake it first.');
        }
        if ($server->status !== GpuServer::STATUS_OFF) {
            throw new RuntimeException("Slot {$server->slot} is busy ({$server->status}).");
        }
    }

    private function provision(GpuServer $server): void
    {
        try {
            $res = $this->orchestrator->provision($server->slot, (string) $server->role);
            $server->instance_id = $res['instance_id'];
            $server->status = GpuServer::STATUS_BOOTING;
            $server->save();
        } catch (Throwable $e) {
            $this->park($server, $e);
            throw $e;
        }
    }

    private function park(GpuServer $server, Throwable $e): void
    {
        $server->status = GpuServer::STATUS_ERROR;
        $server->status_detail = mb_substr($e->getMessage(), 0, 480);
        $server->save();
    }
}
