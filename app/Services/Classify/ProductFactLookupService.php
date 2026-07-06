<?php

namespace App\Services\Classify;

use App\Models\ItemTranslation;
use App\Models\ProductFact;
use App\Services\Llm\OpenRouterClient;
use App\Support\LlmLog;
use Throwable;

/**
 * Acquires one missing fact needed to resolve a classification fork ("is this a
 * plastic article or a medical device?") from the model's product/brand
 * knowledge — never guessing. Cached per (item, question) so it is fetched once
 * and reused. Shared: valuable to any mechanism, not just the broker.
 */
class ProductFactLookupService
{
    public function __construct(private readonly OpenRouterClient $llm) {}

    /**
     * Return the acquired fact string, or null if it cannot be determined (the
     * caller must NOT proceed as if the fact were known).
     */
    public function lookup(string $text, string $question, float $minConfidence = 0.7): ?string
    {
        $sourceHash = ItemTranslation::hashFor($text);
        $criterionHash = hash('sha256', mb_strtolower(trim($question)));

        $cached = ProductFact::where('source_hash', $sourceHash)
            ->where('criterion_hash', $criterionHash)
            ->first();
        if ($cached !== null) {
            return $cached->known ? $cached->fact : null;
        }

        try {
            $messages = [
                ['role' => 'system', 'content' => $this->prompt()],
                ['role' => 'user', 'content' => "ITEM: {$text}\nQUESTION: {$question}"],
            ];
            $response = $this->llm->jsonWithUsage($messages, ['model' => (string) config('classify.broker.fact_model', 'openai/gpt-4o')]);
            LlmLog::record('broker_fact', $response['model'], $response['usage'], $response['latency_ms'] ?? 0,
                'ok', $response['raw'] ?? null, $messages, 'broker', null, ['q' => mb_substr($question, 0, 80)]);

            $d = $response['data'];
            $fact = trim((string) ($d['fact'] ?? ''));
            $confidence = (float) ($d['confidence'] ?? 0);
            $accepted = (bool) ($d['known'] ?? false) && $confidence >= $minConfidence && $fact !== '';

            ProductFact::create([
                'source_hash' => $sourceHash,
                'criterion_hash' => $criterionHash,
                'fact' => $fact !== '' ? $fact : null,
                'known' => $accepted,
                'confidence' => $confidence,
                'source' => 'model',
                'model' => $response['model'],
                'usage' => $response['usage'],
            ]);

            return $accepted ? $fact : null;
        } catch (Throwable) {
            return null; // graceful — an unavailable fact just means the fork stays undecided
        }
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
        You answer ONE specific factual question about a product, using general
        product/brand knowledge, to help classify it into a customs nomenclature.
        Do NOT guess: if you do not genuinely know, set "known" to false. When you
        do know, answer concisely (in Azerbaijani) with the functional fact that
        settles the question (e.g. what the product fundamentally IS).
        Respond with strict JSON only:
        {"known": true, "fact": "...", "confidence": 0.0}
        PROMPT;
    }
}
