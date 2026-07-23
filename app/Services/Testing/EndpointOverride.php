<?php

namespace App\Services\Testing;

use App\Models\TestRun;

/**
 * Applies a test run's OPTIONAL model/endpoint override to config for the duration of
 * ONE mechanism job, then restores it. Queue workers are reused, so leaving the config
 * mutated would let the next run inherit the override — hence apply() returns the prior
 * values for the caller to restore() in a finally.
 *
 * Only the DECISION stages (both rerank tiers, broker, direct) are routed to the
 * override model — expand + web search stay on prod, exactly like
 * `classify:accuracy-test --decider`. Retrieval (vector embeddings via Ollama) is
 * unaffected. The candidate is a "nebius:"-prefixed id so OpenRouterClient routes it
 * through the Nebius provider path, whose base_url/api_key we repoint at the external
 * endpoint (the rented GPU's vLLM).
 *
 * A normal run has no override → apply() is a no-op → the run mirrors prod (the
 * subsystem's whole point: no drift, no leak).
 */
class EndpointOverride
{
    /**
     * Config keys this swaps (and must restore) — the decision stages, the optional
     * expand stage, and the endpoint. All are captured up front so restore() reverts
     * cleanly whether or not each was actually changed.
     */
    private const KEYS = [
        'services.openrouter.classify_model',
        'services.openrouter.classify_model_tier1',
        'classify.broker.model',
        'classify.broker.brief_model',
        'classify.broker.fact_model',
        'classify.direct.model',
        'classify.direct.granularity',
        'classify.broker.answer_granularity',
        'classify.expand_model',
        'services.nebius.base_url',
        'services.nebius.api_key',
    ];

    /**
     * Route this run's decision stages at its endpoint. Returns the prior config values
     * so the caller can restore() them afterwards. No-op (returns []) when the run has
     * no override.
     *
     * @return array<string, mixed> prior values keyed by config path (empty = nothing applied)
     */
    public static function apply(TestRun $run): array
    {
        $model = trim((string) $run->model_override);
        if ($model === '') {
            return [];
        }
        // Force the Nebius provider path; base_url below points it at the endpoint.
        if (! str_starts_with($model, 'nebius:')) {
            $model = 'nebius:'.$model;
        }

        $prior = [];
        foreach (self::KEYS as $k) {
            $prior[$k] = config($k);
        }

        config([
            'services.openrouter.classify_model' => $model,
            'services.openrouter.classify_model_tier1' => $model,
            'classify.broker.model' => $model,
            'classify.broker.brief_model' => $model,
            'classify.broker.fact_model' => $model,
            'classify.direct.model' => $model,
            // The candidate is a fine-tuned 4-digit-heading model — vote at heading,
            // matching how it was trained/evaluated (not the 10-digit 'code' path,
            // which would unfairly make it abstain).
            'classify.direct.granularity' => 'heading',
            'classify.broker.answer_granularity' => 'heading',
        ]);
        // Optional: route query-expansion at the endpoint too, with its OWN model —
        // the fine-tuned decision model can't expand, so a full-GPU run uses base here.
        // Blank → expand stays on prod (keeps retrieval identical across an A/B pair).
        if (($expand = trim((string) $run->expand_model_override)) !== '') {
            config(['classify.expand_model' => str_starts_with($expand, 'nebius:') ? $expand : 'nebius:'.$expand]);
        }
        if (($url = trim((string) $run->endpoint_base_url)) !== '') {
            config(['services.nebius.base_url' => $url]);
        }
        if (($key = trim((string) $run->endpoint_api_key)) !== '') {
            config(['services.nebius.api_key' => $key]);
        }

        return $prior;
    }

    /**
     * Restore the config captured by apply(). Safe to call with [] (does nothing).
     *
     * @param  array<string, mixed>  $prior
     */
    public static function restore(array $prior): void
    {
        if ($prior !== []) {
            config($prior);
        }
    }
}
