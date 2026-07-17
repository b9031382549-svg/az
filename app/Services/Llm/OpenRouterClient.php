<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class OpenRouterClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly string $defaultModel,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rtrim((string) config('services.openrouter.base_url'), '/'),
            config('services.openrouter.api_key'),
            (string) config('services.openrouter.model'),
            (int) config('services.openrouter.timeout'),
        );
    }

    /**
     * Resolve the target provider + real model from a per-call model string.
     * A "nebius:" prefix routes the call to Nebius Token Factory (OpenAI-
     * compatible); anything else uses the default OpenRouter connection. This
     * keeps both providers available and switchable PER STAGE via config alone,
     * e.g. classify.expand_model = "nebius:deepseek-ai/DeepSeek-V4-Pro".
     *
     * @return array{name: string, base_url: string, api_key: ?string, model: string, key_env: string}
     */
    private function resolveProvider(string $model): array
    {
        if (str_starts_with($model, 'nebius:')) {
            return [
                'name' => 'Nebius',
                'base_url' => rtrim((string) config('services.nebius.base_url'), '/'),
                'api_key' => config('services.nebius.api_key'),
                'model' => substr($model, strlen('nebius:')),
                'key_env' => 'NEBIUS_API_KEY',
            ];
        }

        return [
            'name' => 'OpenRouter',
            'base_url' => $this->baseUrl,
            'api_key' => $this->apiKey,
            'model' => $model,
            'key_env' => 'OPENROUTER_API_KEY',
        ];
    }

    /**
     * Send a chat completion and return content together with token usage.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{content: string, usage: array<string, int>, model: string}
     */
    public function complete(array $messages, array $options = []): array
    {
        // Pick the provider (OpenRouter by default, Nebius when the model is
        // prefixed "nebius:") from the per-call model, so a single stage can be
        // routed to either provider by config alone.
        $model = (string) ($options['model'] ?? $this->defaultModel);
        unset($options['model']);
        $provider = $this->resolveProvider($model);

        if (empty($provider['api_key'])) {
            throw new RuntimeException($provider['key_env'].' is not configured.');
        }

        // A per-call HTTP timeout (e.g. for a slow reasoning model) — not an API
        // parameter, so pull it out of $options before it reaches the payload.
        $timeout = (int) ($options['timeout'] ?? $this->timeout);
        unset($options['timeout']);

        $payload = array_merge([
            'temperature' => 0,
            'messages' => $messages,
        ], $options, ['model' => $provider['model']]);

        $response = Http::withToken($provider['api_key'])
            ->withHeaders([
                // Optional attribution headers recommended by OpenRouter.
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])
            ->timeout($timeout)
            ->acceptJson()
            ->post($provider['base_url'].'/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                $provider['name'].' request failed ('.$response->status().'): '.$response->body()
            );
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new RuntimeException('OpenRouter returned an empty response.');
        }

        return [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => (int) $response->json('usage.prompt_tokens', 0),
                'completion_tokens' => (int) $response->json('usage.completion_tokens', 0),
                'total_tokens' => (int) $response->json('usage.total_tokens', 0),
                // Prompt tokens served from the provider's prefix cache (billed at a
                // fraction). Reported as usage.prompt_tokens_details.cached_tokens by
                // OpenAI/DeepSeek via OpenRouter; 0 when the provider doesn't report it.
                'cached_tokens' => (int) $response->json('usage.prompt_tokens_details.cached_tokens', 0),
            ],
            'model' => (string) $response->json('model', $payload['model']),
            // Web-search citations (present when the model ran with the `:online`
            // suffix / web plugin); empty otherwise.
            'annotations' => $this->webSources($response->json('choices.0.message.annotations')),
        ];
    }

    /**
     * Flatten OpenRouter web-search annotations to [{url, title}].
     *
     * @return array<int, array{url: string, title: string}>
     */
    private function webSources(mixed $annotations): array
    {
        if (! is_array($annotations)) {
            return [];
        }
        $out = [];
        foreach ($annotations as $a) {
            $u = is_array($a) ? ($a['url_citation'] ?? null) : null;
            if (is_array($u) && ! empty($u['url'])) {
                $out[] = ['url' => (string) $u['url'], 'title' => (string) ($u['title'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * Send a chat completion request and return the assistant message content.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): string
    {
        return $this->complete($messages, $options)['content'];
    }

    /**
     * Convenience for prompts that must return JSON. Returns the decoded array.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    public function json(array $messages, array $options = []): array
    {
        return $this->jsonWithUsage($messages, $options)['data'];
    }

    /**
     * JSON prompt that also returns token usage for accounting.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{data: array<string, mixed>, usage: array<string, int>, model: string}
     */
    public function jsonWithUsage(array $messages, array $options = []): array
    {
        $options['response_format'] ??= ['type' => 'json_object'];

        // Retry the whole call. Catch Throwable, not just RuntimeException — a
        // transient timeout/network blip throws ConnectionException (NOT a
        // RuntimeException) and must be retried too. Covers: connection errors,
        // HTTP 4xx/5xx, and unparseable/empty responses.
        $attempts = 4;
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $opts = $options;
                // On a retry, nudge temperature so a deterministic bad/unparseable
                // output (temperature 0) is not reproduced identically.
                if ($attempt > 1 && ! isset($options['temperature'])) {
                    $opts['temperature'] = min(0.4, 0.15 * ($attempt - 1));
                }

                $startedAt = microtime(true);
                $result = $this->complete($messages, $opts);

                return [
                    'data' => JsonExtractor::decode($result['content']),
                    'usage' => $result['usage'],
                    'model' => $result['model'],
                    'raw' => $result['content'],
                    'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ];
            } catch (Throwable $e) {
                $lastError = $e;
                if ($attempt < $attempts) {
                    usleep((int) (500000 * (2 ** ($attempt - 1)))); // 0.5s, 1s, 2s backoff
                }
            }
        }

        throw $lastError;
    }
}
