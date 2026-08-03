<?php

namespace App\Services\Gpu;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Thin PHP wrapper over resources/gpu-scripts/orchestrator.py — the app's only door to
 * Nebius + the GPU VMs. Each method runs ONE bounded orchestrator subcommand (launch-and-
 * return or a health probe, never training-length) and decodes its single JSON line.
 *
 * Kept deliberately dumb: no state, no DB — the managers (GpuServerManager /
 * FinetuneManager) hold the state machine and decide WHAT to call. That separation is what
 * lets us swap Nebius for owned servers later by only changing the orchestrator, not this.
 */
class GpuOrchestrator
{
    /**
     * Run an orchestrator subcommand and return its decoded JSON. Throws when the process
     * fails to run, emits no JSON, or returns {"ok": false} — so callers can try/catch and
     * park a server/run in the `error` state with the message.
     *
     * @param  array<int, string>  $args
     * @return array<string, mixed>
     */
    public function call(string $command, array $args = []): array
    {
        $cmd = array_merge(
            [(string) config('gpu.python_bin'), (string) config('gpu.orchestrator'), $command],
            $args,
        );

        $result = Process::timeout((int) config('gpu.process_timeout'))
            ->env($this->env())
            ->run($cmd);

        $stdout = trim($result->output());
        if ($stdout === '') {
            throw new RuntimeException("orchestrator {$command}: no output (stderr: ".trim($result->errorOutput()).')');
        }

        $decoded = json_decode($stdout, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("orchestrator {$command}: non-JSON output: ".mb_substr($stdout, 0, 300));
        }
        if (($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException("orchestrator {$command}: ".($decoded['error'] ?? 'failed'));
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    public function provision(string $slot, string $role): array
    {
        return $this->call('provision', ['--slot', $slot, '--role', $role]);
    }

    /** @return array<string, mixed> */
    public function status(string $instanceId, ?string $apiKey = null): array
    {
        $args = ['--instance', $instanceId, '--ssh-key', (string) config('gpu.ssh_key')];
        if ($apiKey) {
            $args[] = '--api-key';
            $args[] = $apiKey;
        }

        return $this->call('status', $args);
    }

    /** @return array<string, mixed> */
    public function start(string $instanceId): array
    {
        return $this->call('start', ['--instance', $instanceId]);
    }

    /** @return array<string, mixed> */
    public function serveBase(string $ip, string $apiKey): array
    {
        return $this->call('serve-base', [
            '--ip', $ip, '--api-key', $apiKey, '--ssh-key', (string) config('gpu.ssh_key'),
        ]);
    }

    /** @return array<string, mixed> */
    public function deployAdapter(string $ip, string $apiKey, string $adapterLocal): array
    {
        return $this->call('deploy-adapter', [
            '--ip', $ip, '--api-key', $apiKey, '--adapter-local', $adapterLocal,
            '--ssh-key', (string) config('gpu.ssh_key'),
        ]);
    }

    /** @return array<string, mixed> */
    public function startTraining(string $ip, string $dataPath, float $epochs): array
    {
        return $this->call('start-training', [
            '--ip', $ip, '--data', $dataPath, '--epochs', (string) $epochs,
            '--ssh-key', (string) config('gpu.ssh_key'),
        ]);
    }

    /** @return array<string, mixed> */
    public function trainingStatus(string $ip): array
    {
        return $this->call('training-status', ['--ip', $ip, '--ssh-key', (string) config('gpu.ssh_key')]);
    }

    /** @return array<string, mixed> */
    public function fetchAdapter(string $ip, string $vpsPath): array
    {
        return $this->call('fetch-adapter', [
            '--ip', $ip, '--vps-path', $vpsPath, '--ssh-key', (string) config('gpu.ssh_key'),
        ]);
    }

    /** @return array<string, mixed> */
    public function destroy(string $instanceId): array
    {
        return $this->call('destroy', ['--instance', $instanceId]);
    }

    /**
     * Env the orchestrator reads (Nebius ids + SSH pubkey path). Only non-empty values are
     * passed so a missing golden snapshot surfaces as the orchestrator's own clear error.
     *
     * @return array<string, string>
     */
    private function env(): array
    {
        return array_filter([
            'NEBIUS_PROJECT_ID' => (string) config('gpu.nebius.project_id'),
            'NEBIUS_SUBNET_ID' => (string) config('gpu.nebius.subnet_id'),
            'NEBIUS_GOLDEN_SNAPSHOT_ID' => (string) config('gpu.nebius.golden_snapshot_id'),
            'GPU_PLATFORM' => (string) config('gpu.platform'),
            'GPU_PRESET' => (string) config('gpu.preset'),
            'GPU_MAX_MODEL_LEN' => (string) config('gpu.max_model_len'),
            'GPU_MEM_UTIL' => (string) config('gpu.gpu_mem_util'),
            'SSH_KEY' => (string) config('gpu.ssh_key'),
            'SSH_PUBKEY' => (string) config('gpu.ssh_pubkey'),
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'HOME' => (string) (getenv('HOME') ?: '/root'),
        ], fn ($v) => $v !== '');
    }
}
