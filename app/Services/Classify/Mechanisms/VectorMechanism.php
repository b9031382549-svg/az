<?php

namespace App\Services\Classify\Mechanisms;

use App\Services\Classify\ClassifierService;

// The existing hybrid-retrieval + two-tier LLM re-rank pipeline, wrapped as a
// mechanism. Pure adapter: ClassifierService::classify() already returns the
// full result array (code/candidates/semantic_sim/tier/usage); this only maps
// it onto a MechanismResult. Behaviour is unchanged from the original path.
final class VectorMechanism implements ClassifierMechanism
{
    public function __construct(private readonly ClassifierService $classifier) {}

    public function key(): string
    {
        return 'vector';
    }

    public function classify(string $text): MechanismResult
    {
        return self::mapResult($this->classifier->classify($text));
    }

    /**
     * Map ClassifierService::classify()'s result array onto a MechanismResult.
     *
     * @param  array<string, mixed>  $r
     */
    public static function mapResult(array $r): MechanismResult
    {
        $tier = $r['tier'] ?? null;
        $model = $tier === 1
            ? (string) config('services.openrouter.classify_model_tier1')
            : (string) config('services.openrouter.classify_model');

        return new MechanismResult(
            matchedCode: $r['code'] ?? null,
            catalogId: $r['catalog_id'] ?? null,
            kind: $r['kind'] ?? null,
            confidence: isset($r['confidence']) ? (float) $r['confidence'] : null,
            status: (string) ($r['status'] ?? 'no_match'),
            candidates: $r['candidates'] ?? [],
            explanation: $r['reason'] ?? ($r['error'] ?? null),
            model: ($r['code'] ?? null) !== null ? $model : null,
            tier: $tier,
            usage: $r['usage'] ?? [],
        );
    }
}
