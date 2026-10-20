<?php

namespace App\Services\Embeddings;

use App\Services\Llm\ModelUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Turns texts into vectors for the similarity search. One job, two transports, picked by
 * config services.embeddings.driver: Ollama's /api/embed (our servers), or any
 * OpenAI-compatible POST /embeddings (vLLM, TEI, llama.cpp server — an on-premise GPU).
 * The vectors stored in the DB and the query vectors must come from the same model.
 */
final class Embedder
{
    public const DRIVER_OLLAMA = 'ollama';

    public const DRIVER_OPENAI = 'openai';

    public function __construct(
        private readonly string $driver,
        private readonly string $url,
        private readonly string $model,
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 300,
    ) {}

    public static function fromConfig(): self
    {
        $timeout = (int) config('services.embeddings.timeout', 300);

        return self::driver() === self::DRIVER_OPENAI
            ? new self(self::DRIVER_OPENAI, rtrim((string) config('services.embeddings.base_url'), '/'),
                self::configuredModel(), config('services.embeddings.api_key'), $timeout)
            : new self(self::DRIVER_OLLAMA, rtrim((string) config('services.ollama.url'), '/'),
                self::configuredModel(), null, $timeout);
    }

    /** The configured transport; anything but "openai" is Ollama, the default. */
    public static function driver(): string
    {
        return config('services.embeddings.driver') === self::DRIVER_OPENAI ? self::DRIVER_OPENAI : self::DRIVER_OLLAMA;
    }

    /** The model the configured transport embeds with (health check, /api/version). */
    public static function configuredModel(): string
    {
        return self::driver() === self::DRIVER_OPENAI
            ? (string) config('services.embeddings.model')
            : (string) config('services.ollama.embed_model');
    }

    /**
     * Embed a batch of texts. Returns one vector (array<float>) per input, in input order.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array
    {
        $texts = array_values($texts);
        $openai = $this->driver === self::DRIVER_OPENAI;

        $request = Http::timeout($this->timeout)->acceptJson();
        if ($openai && filled($this->apiKey)) {
            $request->withToken($this->apiKey);
        }

        try {
            $response = $request->post($this->url.($openai ? '/embeddings' : '/api/embed'), [
                'model' => $this->model,
                'input' => $texts,
            ]);
        } catch (ConnectionException $e) {
            throw new ModelUnavailable('Embedding server unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $message = 'Embedding request failed ('.$response->status().'): '.$response->body();
            throw ModelUnavailable::isUnavailableStatus($response->status())
                ? new ModelUnavailable($message)
                : new RuntimeException($message);
        }

        // OpenAI: data[] carries an index per vector (the spec does not promise the order);
        // Ollama: embeddings[] in input order.
        $embeddings = $openai
            ? collect((array) $response->json('data'))->sortBy('index')->pluck('embedding')->values()->all()
            : $response->json('embeddings');

        if (! is_array($embeddings) || count($embeddings) !== count($texts)) {
            throw new RuntimeException('The embedding server returned an unexpected payload.');
        }

        return $embeddings;
    }

    /**
     * @return array<int, float>
     */
    public function embedOne(string $text): array
    {
        return $this->embed([$text])[0];
    }

    /**
     * Format a vector for a pgvector parameter, e.g. "[0.1,0.2,...]".
     *
     * @param  array<int, float>  $vector
     */
    public static function toSqlVector(array $vector): string
    {
        return '['.implode(',', $vector).']';
    }
}
