<?php

namespace App\Services\Classify\Mechanisms;

use App\Services\Classify\ClassifierService;
use App\Services\Classify\ProductBriefService;

// The hybrid-retrieval + two-tier LLM re-rank pipeline, wrapped as a mechanism.
// Seeds retrieval with the shared product brief's clean IDENTITY (when enabled) so
// it keys off what the item IS, not its surface tokens; then maps the result array
// onto a MechanismResult.
final class VectorMechanism implements ClassifierMechanism
{
    public function __construct(
        private readonly ClassifierService $classifier,
        private readonly ProductBriefService $briefs,
    ) {}

    public function key(): string
    {
        return 'vector';
    }

    public function classify(string $text): MechanismResult
    {
        // The brief is cached/shared with the broker — no extra call. It only steers
        // retrieval + re-rank; vector still runs its own hybrid search independently.
        $identity = null;
        if (config('classify.vector.use_brief_query', true)) {
            $brief = $this->briefs->brief($text);
            $identity = is_array($brief) ? ($brief['identity'] ?? null) : null;
        }

        return self::mapResult($this->classifier->classify($text, $identity));
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
            trace: $r['trace'] ?? [],
        );
    }
}
