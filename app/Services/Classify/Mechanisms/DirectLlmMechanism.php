<?php

namespace App\Services\Classify\Mechanisms;

use App\Models\CatalogCode;
use App\Services\Llm\OpenRouterClient;
use App\Support\LlmLog;
use Throwable;

/**
 * A third, independent path: a "cold" classification straight from a reasoning
 * model's OWN knowledge — no retrieval over our catalog, no tree descent. Given the
 * raw Azerbaijani item it returns a single 10-digit XİF MN code + a short reason, or
 * abstains ("code": null) when it cannot understand the item. A genuinely different
 * METHOD from vector-retrieval and broker-descent, so its agreement/disagreement is
 * an independent vote in the consensus. A returned code is trusted only if it
 * actually exists in the catalog (the model recalls from memory and may cite a
 * plausible-but-nonexistent code).
 */
final class DirectLlmMechanism implements ClassifierMechanism
{
    public function __construct(private readonly OpenRouterClient $llm) {}

    public function key(): string
    {
        return 'direct';
    }

    public function classify(string $text): MechanismResult
    {
        $text = trim($text);
        $model = (string) config('classify.direct.model', 'deepseek/deepseek-r1');
        if ($text === '') {
            return new MechanismResult(null, null, null, null, 'error', explanation: 'Empty item.', model: $model);
        }

        $usage = [];
        try {
            $messages = [
                ['role' => 'system', 'content' => $this->prompt()],
                ['role' => 'user', 'content' => "ITEM: {$text}"],
            ];
            $resp = $this->llm->complete($messages, [
                'model' => $model,
                'timeout' => (int) config('classify.direct.timeout', 300),
            ]);
            $usage = $resp['usage'] ?? [];
            LlmLog::record('direct', $resp['model'] ?? $model, $usage, 0, 'ok',
                $resp['content'] ?? null, $messages, null, null, ['item' => mb_substr($text, 0, 80)]);

            $d = $this->parse((string) ($resp['content'] ?? ''));
        } catch (Throwable $e) {
            // A slow reasoning model may time out — abstain (an empty vote), never block.
            return new MechanismResult(null, null, null, null, 'no_match',
                explanation: 'Direct model unavailable: '.mb_substr($e->getMessage(), 0, 120), model: $model);
        }

        $cat = $d['code'] !== null ? $this->snapToCatalog($d['code']) : null;
        if ($cat === null) {
            return new MechanismResult(null, null, null, $d['confidence'], 'no_match',
                explanation: $d['code'] !== null
                    ? "Recalled {$d['code']} — no catalog code in that subheading, abstaining. ".(string) $d['reason']
                    : ($d['reason'] ?? 'Model could not identify the item.'),
                model: $model, usage: $usage);
        }

        $auto = (float) config('classify.auto_confirm', 0.8);
        // Note when the recalled code was snapped to a real catalog code (models
        // recall the right chapter/subheading but often miss the exact last digits).
        $snap = $cat->code !== $d['code'] ? "recalled {$d['code']} → {$cat->code}. " : '';

        return new MechanismResult(
            matchedCode: $cat->code,
            catalogId: $cat->id,
            kind: $cat->kind,
            confidence: $d['confidence'],
            status: $d['confidence'] !== null && $d['confidence'] >= $auto ? 'auto_confirmed' : 'needs_review',
            explanation: $snap.(string) ($d['reason'] ?? ''),
            model: $model,
            usage: $usage,
        );
    }

    /**
     * Resolve a recalled code to a REAL catalog entry: exact match if it exists,
     * else the first active code in the same 6-digit subheading — "right subheading,
     * wrong last digits" is the common cold-recall error. Null when the subheading
     * itself has no catalog code (the recall was genuinely off).
     */
    private function snapToCatalog(string $code): ?CatalogCode
    {
        $exact = CatalogCode::where('code', $code)->where('is_active', true)->first();
        if ($exact !== null) {
            return $exact;
        }

        return CatalogCode::where('code', 'like', mb_substr($code, 0, 6).'%')
            ->where('is_active', true)
            ->orderBy('code')
            ->first();
    }

    /**
     * Extract {code, confidence, reason} from the model output — a reasoning model
     * emits chain-of-thought (with stray braces), so strip <think> and take the LAST
     * JSON object that actually carries a "code" key.
     *
     * @return array{code: ?string, confidence: ?float, reason: ?string}
     */
    private function parse(string $content): array
    {
        $content = (string) preg_replace('#<think>.*?</think>#is', '', $content);
        $d = null;
        if (preg_match_all('/\{[^{}]*\}/s', $content, $m) && $m[0] !== []) {
            foreach (array_reverse($m[0]) as $j) {
                $try = json_decode($j, true);
                if (is_array($try) && array_key_exists('code', $try)) {
                    $d = $try;
                    break;
                }
            }
        }
        $d ??= [];
        $code = preg_replace('/\D+/', '', (string) ($d['code'] ?? ''));
        $reason = trim((string) ($d['reason'] ?? ''));

        return [
            'code' => strlen((string) $code) === 10 ? $code : null,
            'confidence' => isset($d['confidence']) ? round((float) $d['confidence'], 3) : null,
            'reason' => $reason !== '' ? $reason : null,
        ];
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
        You are an expert in Azerbaijan's XİF MN customs nomenclature (aligned with the
        HS / ТН ВЭД code system). You receive ONE line item from an Azerbaijani
        e-invoice. From your OWN knowledge — assume NO database — give the single most
        likely 10-digit XİF MN code, with a one-line reason.
        - The text is Azerbaijani and often noisy (brands, sizes, transliteration,
          dropped diacritics). Read the head-noun; ignore brand/size noise.
        - If you genuinely CANNOT tell what the item is (a garbled token, or a bare
          brand/code with no product noun), return "code": null. Do NOT guess a code
          for an unintelligible item.
        - Give a FULL 10-digit code, digits only.

        Respond with strict JSON only:
        {"code": "<10 digits or null>", "confidence": 0.0, "reason": "short"}
        PROMPT;
    }
}
