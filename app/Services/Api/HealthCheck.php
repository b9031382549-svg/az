<?php

namespace App\Services\Api;

use App\Services\Classify\SorterClient;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * GET /api/health-check — is every part the classifier needs up right now? "Is it working"
 * checks only, each instant and free: nothing gets classified, embedded or generated, so
 * the answer stays fast under load. A busy embedder must not read as down, because the
 * host's monitoring may restart the service on a 500. An LLM provider is only asked for
 * its model list, cached for a few minutes.
 */
class HealthCheck
{
    /** Seconds to wait for each service. */
    private const TIMEOUT = 3;

    /** Seconds a provider's model list is reused. */
    private const MODELS_TTL = 300;

    public function __construct(
        private readonly SorterClient $sorter,
        private readonly OpenRouterClient $llm,
    ) {}

    /** @return array<string, string> failed check => why; empty when everything works */
    public function failures(): array
    {
        $checks = [
            'database' => fn () => $this->database(),
            'redis' => fn () => $this->redis(),
            'workers' => fn () => $this->workers(),
            'sorter' => fn () => $this->sorterService(),
            'embedder' => fn () => $this->embedder(),
            'vector search' => fn () => $this->vectorSearch(),
            'llm' => fn () => $this->llmModels(),
        ];

        $failed = [];
        foreach ($checks as $name => $check) {
            try {
                $problem = $check();
            } catch (Throwable $e) {
                $problem = $e->getMessage();
            }
            if ($problem !== null) {
                $failed[$name] = $problem;
            }
        }

        return $failed;
    }

    private function database(): ?string
    {
        DB::select('select 1');

        return null;
    }

    private function redis(): ?string
    {
        Redis::connection((string) config('horizon.use', 'default'))->ping();

        return null;
    }

    /** Without a running worker an accepted request would never be classified. */
    private function workers(): ?string
    {
        $masters = app(MasterSupervisorRepository::class)->all();
        if ($masters === []) {
            return 'no queue workers are running (Horizon is down)';
        }

        return collect($masters)->contains(fn ($master) => ($master->status ?? null) === 'paused')
            ? 'the queue workers are paused (Horizon is paused)'
            : null;
    }

    /** Its /health answers without running the model, so a long queue at the sorter doesn't count. */
    private function sorterService(): ?string
    {
        if (! $this->sorter->enabled()) {
            return null;
        }

        $body = Http::timeout(self::TIMEOUT)->acceptJson()
            ->get(rtrim((string) config('classify.sorter.url'), '/').'/health')
            ->throw()->json();

        return ($body['ok'] ?? false) === true ? null : 'the sorter service has no model loaded';
    }

    private function embedder(): ?string
    {
        $model = (string) config('services.ollama.embed_model');
        $names = (array) Http::timeout(self::TIMEOUT)->acceptJson()
            ->get(rtrim((string) config('services.ollama.url'), '/').'/api/tags')
            ->throw()->json('models.*.name');

        // Ollama lists names with their tag: "bge-ft-v3:latest".
        $present = collect($names)->contains(fn ($name) => $name === $model || str_starts_with((string) $name, $model.':'));

        return $present ? null : "Ollama does not have the embedding model {$model}";
    }

    /** One nearest-neighbour lookup on the HNSW index, with a vector already in the catalog. */
    private function vectorSearch(): ?string
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return null; // pgvector lives in PostgreSQL only (the test suite runs on sqlite)
        }

        $vector = DB::table('catalog')->whereNotNull('embedding')->value(DB::raw('embedding::text'));
        if ($vector === null) {
            return 'the catalog has no embeddings';
        }
        $rows = DB::select('select id from catalog where embedding is not null order by embedding <=> ?::vector limit 3', [$vector]);

        return count($rows) === 3 ? null : 'the nearest-neighbour search returned too few rows';
    }

    /**
     * The models that decide answers must still be offered by their providers. A provider
     * silently retiring a model is how Direct turned every item into no_match for five
     * days (2026-09-25..30) while everything else looked fine.
     */
    private function llmModels(): ?string
    {
        $models = array_filter([
            in_array('direct', (array) config('classify.mechanisms.enabled', []), true) ? (string) config('classify.direct.model') : '',
            config('classify.flow.ensemble_resolver') ? (string) config('classify.flow.ensemble.model') : '',
            config('classify.flow.ensemble_resolver') ? (string) config('classify.flow.ensemble.understand_model') : '',
            config('classify.search_resolver.enabled') ? (string) config('classify.search_resolver.model') : '',
        ]);

        $missing = [];
        foreach (array_unique($models) as $configured) {
            $provider = $this->llm->resolveProvider($configured, touch: false);
            $model = preg_replace('/:online$/', '', $provider['model']); // web search is a plugin, not a model id
            if (! in_array($model, $this->offeredModels($provider['base_url'], $provider['api_key']), true)) {
                $missing[] = "{$provider['name']} does not offer {$model}";
            }
        }

        return $missing === [] ? null : implode('; ', $missing);
    }

    /** @return array<int, string> */
    private function offeredModels(string $baseUrl, ?string $apiKey): array
    {
        return Cache::remember('health:models:'.md5($baseUrl), self::MODELS_TTL, fn () => (array) Http::timeout(self::TIMEOUT)
            ->acceptJson()
            ->withToken((string) $apiKey)
            ->get($baseUrl.'/models')
            ->throw()
            ->json('data.*.id'));
    }
}
