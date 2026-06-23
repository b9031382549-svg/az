<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Http;
use RuntimeException;

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
     * Send a chat completion request and return the assistant message content.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): string
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured.');
        }

        $payload = array_merge([
            'model' => $this->defaultModel,
            'temperature' => 0,
            'messages' => $messages,
        ], $options);

        $response = Http::withToken($this->apiKey)
            ->withHeaders([
                // Optional attribution headers recommended by OpenRouter.
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])
            ->timeout($this->timeout)
            ->acceptJson()
            ->post($this->baseUrl.'/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'OpenRouter request failed ('.$response->status().'): '.$response->body()
            );
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new RuntimeException('OpenRouter returned an empty response.');
        }

        return $content;
    }

    /**
     * Convenience for prompts that must return JSON. Returns the decoded array.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    public function json(array $messages, array $options = []): array
    {
        $options['response_format'] ??= ['type' => 'json_object'];
        $raw = $this->chat($messages, $options);

        return JsonExtractor::decode($raw);
    }
}
