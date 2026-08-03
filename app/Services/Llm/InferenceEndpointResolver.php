<?php

namespace App\Services\Llm;

use App\Models\GpuServer;

/**
 * Resolves a "gpu:" logical model to a concrete provider endpoint, LIVE per call.
 *
 * The whole system (prod classifier AND the testing tab) points its decision-stage models
 * at logical names — `gpu:tuned` (fine-tuned) and `gpu:base` (stock) — instead of a fixed
 * host. This resolver turns those into the currently-active GPU server's connection data,
 * or, when no server is active, falls back to Token Factory serving the base model (no
 * fine-tune). Flipping which GpuServer is active is the zero-downtime switch — no config
 * edit, no redeploy. On owned fixed servers later, the slot rows just hold static data.
 *
 * Read fresh from the DB on every call (OpenRouterClient::resolveProvider re-resolves per
 * request), so long-lived queue workers never serve a stale endpoint after a switch.
 */
class InferenceEndpointResolver
{
    /** The logical model prefix this resolver owns. */
    public const PREFIX = 'gpu:';

    /**
     * Resolve a "gpu:"-prefixed model, or return null for anything else (the caller then
     * handles nebius:/OpenRouter). Always returns a provider array for a gpu: model —
     * either the active server or the Token Factory fallback — so a gpu: call never dead-ends.
     *
     * @return array{name: string, base_url: string, api_key: ?string, model: string, key_env: string}|null
     */
    public function resolve(string $model): ?array
    {
        if (! str_starts_with($model, self::PREFIX)) {
            return null;
        }

        $logical = substr($model, strlen(self::PREFIX)); // 'tuned' | 'base' | explicit
        $server = GpuServer::active();

        if ($server !== null && $server->isServing()) {
            $server->markRequested(); // feed the idle-autostop clock (throttled, cheap)

            return [
                'name' => 'GPU:'.$server->slot,
                'base_url' => rtrim((string) $server->base_url, '/'),
                'api_key' => $server->api_key,
                'model' => $server->modelFor($logical),
                'key_env' => 'GPU server '.$server->slot.' api_key',
            ];
        }

        // Fallback: no active self-hosted server → Token Factory serving the BASE model.
        // The adapter is unavailable here (Token Factory can't serve our LoRA), so a
        // 'tuned' request degrades to base — same as prod runs today. The UI labels this.
        return [
            'name' => 'TokenFactory (fallback, base)',
            'base_url' => rtrim((string) config('services.nebius.base_url'), '/'),
            'api_key' => config('services.nebius.api_key'),
            'model' => (string) config('services.nebius.fallback_model'),
            'key_env' => 'NEBIUS_API_KEY',
        ];
    }
}
