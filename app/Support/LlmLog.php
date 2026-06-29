<?php

namespace App\Support;

use App\Models\LlmUsage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records one LLM call (classifier / NL->SQL) into llm_usage as a full decision
 * log: tokens, latency, tier, status/error and — when services.openrouter
 * .log_payloads is on — the full prompt and response. Never breaks the caller.
 */
class LlmLog
{
    /**
     * @param  array<int, array{role:string, content:string}>  $messages  The prompt sent.
     * @param  array<string, int>  $usage
     * @param  array<string, mixed>  $meta
     */
    public static function record(
        string $purpose,
        string $model,
        array $usage,
        int $latencyMs,
        string $status,
        ?string $response = null,
        array $messages = [],
        ?string $tier = null,
        ?string $error = null,
        array $meta = [],
    ): void {
        $payloads = (bool) config('services.openrouter.log_payloads', false);

        try {
            LlmUsage::create([
                'purpose' => $purpose,
                'tier' => $tier,
                'model' => $model,
                'status' => $status,
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? 0,
                'latency_ms' => $latencyMs,
                'error' => $error,
                'prompt' => $payloads ? json_encode($messages, JSON_UNESCAPED_UNICODE) : null,
                'response' => $payloads ? $response : null,
                'request_id' => app()->bound('request_id') ? app('request_id') : null,
                'meta' => $meta ?: null,
            ]);
        } catch (Throwable $e) {
            Log::warning('llm log failed for '.$purpose.': '.$e->getMessage());
        }
    }
}
