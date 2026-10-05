<?php

namespace App\Services\Api;

/**
 * GET /api/version — which classifier is answering. The classifier is a chain of models, so
 * besides our release version it names each model the chain is configured to use, read
 * from config at call time: whoever hosts it may plug in another LLM, and the answer must
 * say so. Nothing here calls a model.
 */
class ModelVersion
{
    /** @return array{model_version: string, trained_at: string, api_version: string, components: array<string, string>} */
    public function toArray(): array
    {
        return [
            'model_version' => (string) config('api.model.version'),
            'trained_at' => (string) config('api.model.trained_at'),
            'api_version' => (string) config('api.version'),
            'components' => $this->components(),
        ];
    }

    /** @return array<string, string> component => model, only for the parts that are switched on */
    private function components(): array
    {
        $direct = (string) config('classify.direct.model');

        return array_filter([
            'sorter' => config('classify.sorter.url') ? (string) config('classify.sorter.model') : '',
            'embedder' => (string) config('services.ollama.embed_model'),
            'direct' => $direct,
            // A "gpu:" model runs on our own GPU slot; with no slot up, it falls back to this one.
            'direct_fallback' => str_starts_with($direct, 'gpu:') ? (string) config('services.nebius.fallback_model') : '',
            'web_search' => config('classify.search_resolver.enabled') ? (string) config('classify.search_resolver.model') : '',
            // The web search's understanding step also sets aside lines that name no product.
            'web_search_trash_check' => config('classify.search_resolver.enabled') && config('classify.flow.ensemble_resolver')
                && config('classify.search_resolver.trash_check.enabled')
                ? (string) config('classify.flow.ensemble.understand_model') : '',
        ], fn (string $model) => $model !== '');
    }
}
