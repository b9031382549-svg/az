<?php

namespace Tests\Feature\Gpu;

use App\Services\Gpu\GpuOrchestrator;

/**
 * A scripted stand-in for the real GpuOrchestrator so the state-machine tests never touch
 * Nebius or SSH. Each method returns canned data and records that it was called; tests set
 * `statusResponse` / `trainingStatusResponse` to drive the machine step by step.
 */
class FakeOrchestrator extends GpuOrchestrator
{
    public array $statusResponse = ['ok' => true, 'ip' => null, 'ssh_ok' => false, 'vllm_ready' => false, 'models' => []];

    public array $trainingStatusResponse = ['ok' => true, 'state' => 'training', 'step' => 0, 'total_steps' => 100, 'loss' => null, 'eta_seconds' => null];

    public bool $deployed = false;

    public bool $servedBase = false;

    public bool $trainingStarted = false;

    public ?string $destroyedId = null;

    public function __construct()
    {
        // Skip the parent constructor's dependencies — this double calls nothing real.
    }

    public function provision(string $slot, string $role): array
    {
        return ['ok' => true, 'instance_id' => 'computeinstance-fake'];
    }

    public function status(string $instanceId, ?string $apiKey = null): array
    {
        return $this->statusResponse;
    }

    public bool $started = false;

    public function start(string $instanceId): array
    {
        $this->started = true;

        return ['ok' => true];
    }

    public function serveBase(string $ip, string $apiKey): array
    {
        $this->servedBase = true;

        return ['ok' => true];
    }

    public function deployAdapter(string $ip, string $apiKey, string $adapterLocal): array
    {
        $this->deployed = true;

        return ['ok' => true];
    }

    public bool $failStartTraining = false;

    public function startTraining(string $ip, string $dataPath, float $epochs): array
    {
        if ($this->failStartTraining) {
            throw new \RuntimeException('train launch failed: timeout after 120s');
        }
        $this->trainingStarted = true;

        return ['ok' => true, 'heartbeat' => '/home/ubuntu/heartbeat.json'];
    }

    public function trainingStatus(string $ip): array
    {
        return $this->trainingStatusResponse;
    }

    public function fetchAdapter(string $ip, string $vpsPath): array
    {
        return ['ok' => true, 'vps_path' => $vpsPath, 'sha256' => str_repeat('a', 64)];
    }

    public function destroy(string $instanceId): array
    {
        $this->destroyedId = $instanceId;

        return ['ok' => true, 'destroyed' => true];
    }
}
