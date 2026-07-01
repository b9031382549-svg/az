<?php

namespace App\Services\Classify\Mechanisms;

use InvalidArgumentException;

// Holds the enabled search mechanisms (in config order) for the orchestrator to
// fan out over. Built once from config('classify.mechanisms.enabled') in
// AppServiceProvider; new mechanisms are added there, not here.
final class MechanismRegistry
{
    /** @var array<string, ClassifierMechanism> */
    private array $mechanisms = [];

    public function register(ClassifierMechanism $mechanism): void
    {
        $this->mechanisms[$mechanism->key()] = $mechanism;
    }

    public function has(string $key): bool
    {
        return isset($this->mechanisms[$key]);
    }

    public function get(string $key): ClassifierMechanism
    {
        return $this->mechanisms[$key]
            ?? throw new InvalidArgumentException("Unknown classifier mechanism: {$key}");
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->mechanisms);
    }

    /** @return array<int, ClassifierMechanism> */
    public function all(): array
    {
        return array_values($this->mechanisms);
    }
}
