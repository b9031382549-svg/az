<?php

namespace App\Services\Embeddings;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaEmbedder
{
    public function __construct(
        private readonly string $url,
        private readonly string $model,
        private readonly int $dimensions,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rtrim((string) config('services.ollama.url'), '/'),
            (string) config('services.ollama.embed_model'),
            (int) config('services.ollama.dimensions'),
        );
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Embed a batch of texts. Returns one vector (array<float>) per input.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array
    {
        $response = Http::timeout(300)
            ->acceptJson()
            ->post($this->url.'/api/embed', [
                'model' => $this->model,
                'input' => array_values($texts),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Ollama embed failed ('.$response->status().'): '.$response->body());
        }

        $embeddings = $response->json('embeddings');
        if (! is_array($embeddings) || count($embeddings) !== count($texts)) {
            throw new RuntimeException('Ollama returned an unexpected embeddings payload.');
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
