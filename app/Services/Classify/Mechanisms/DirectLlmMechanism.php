<?php

namespace App\Services\Classify\Mechanisms;

use App\Models\CatalogCode;
use App\Services\Llm\OpenRouterClient;
use App\Support\LlmLog;
use Throwable;

/**
 * A third, independent path: a reasoning model that IDENTIFIES the item from its own
 * knowledge (NO web search), then names the XİF MN code, or abstains when it cannot
 * tell what the item is. A genuinely different METHOD from vector-retrieval and
 * broker-descent, so its vote is independent in the 2-of-3 heading consensus.
 *
 * Two granularities (config classify.direct.granularity):
 *  - 'code'    — recall a full 10-digit code, snapped to a real catalog row.
 *  - 'heading' — recall only the 4-digit HS heading (+ good/service). A model recalls
 *                a heading far more reliably than a national 10-digit code, so this
 *                abstains far less and can actually flag services.
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
        $model = (string) config('classify.direct.model', 'openai/gpt-oss-120b');
        if ($text === '') {
            return new MechanismResult(null, null, null, null, 'error', explanation: 'Empty item.', model: $model);
        }

        $mode = (string) config('classify.direct.granularity', 'code');
        $usage = [];
        try {
            $messages = [
                ['role' => 'system', 'content' => $this->prompt($mode)],
                ['role' => 'user', 'content' => "ITEM: {$text}"],
            ];
            $resp = $this->llm->complete($messages, [
                'model' => $model,
                'timeout' => (int) config('classify.direct.timeout', 300),
            ]);
            $usage = $resp['usage'] ?? [];
            LlmLog::record('direct', $resp['model'] ?? $model, $usage, 0, 'ok',
                $resp['content'] ?? null, $messages, null, null, ['item' => mb_substr($text, 0, 80)]);

            if ($mode === 'heading') {
                return $this->buildHeadingResult((string) ($resp['content'] ?? ''), $model, $usage);
            }

            $d = $this->parse((string) ($resp['content'] ?? ''));
        } catch (Throwable $e) {
            // A slow reasoning model may time out — abstain (an empty vote), never block.
            return new MechanismResult(null, null, null, null, 'no_match',
                explanation: 'Direct model unavailable: '.mb_substr($e->getMessage(), 0, 120), model: $model);
        }

        // Web sources the model cited (when it searched) — kept in the reason so the
        // decision page can show where the identification came from.
        $src = $this->sourceNote($resp['annotations'] ?? []);

        $cat = $d['code'] !== null ? $this->snapToCatalog($d['code']) : null;
        if ($cat === null) {
            return new MechanismResult(null, null, null, $d['confidence'], 'no_match',
                explanation: ($d['code'] !== null
                    ? "Recalled {$d['code']} — no catalog code in that subheading, abstaining. ".(string) $d['reason']
                    : ($d['reason'] ?? 'Model could not identify the item.')).$src,
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
            explanation: $snap.(string) ($d['reason'] ?? '').$src,
            model: $model,
            usage: $usage,
        );
    }

    /**
     * A compact " [web: host1, host2]" note from web-search citations, or ''.
     *
     * @param  array<int, array{url: string, title: string}>  $annotations
     */
    private function sourceNote(array $annotations): string
    {
        $hosts = collect($annotations)
            ->map(fn ($s) => parse_url((string) ($s['url'] ?? ''), PHP_URL_HOST))
            ->filter()
            ->map(fn ($h) => preg_replace('/^www\./', '', (string) $h))
            ->unique()
            ->take(3)
            ->implode(', ');

        return $hosts !== '' ? " [web: {$hosts}]" : '';
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

    /**
     * Heading mode: the model recalls only the 4-digit HS heading (or flags a
     * service). Far more reliable than a full 10-digit national code, and all the
     * 2-of-3 heading consensus needs. Abstains only on an unintelligible item or a
     * heading that is not a real active XİF MN position.
     */
    private function buildHeadingResult(string $content, string $model, array $usage): MechanismResult
    {
        $d = $this->parseHeading($content);
        $auto = (float) config('classify.auto_confirm', 0.8);
        $status = $d['confidence'] !== null && $d['confidence'] >= $auto ? 'auto_confirmed' : 'needs_review';

        if ($d['kind'] === 'service') {
            return new MechanismResult('99', null, 'service', $d['confidence'], $status,
                explanation: (string) ($d['reason'] ?? 'Service.'), model: $model, usage: $usage);
        }

        if ($d['heading'] === null || ! $this->isRealHeading($d['heading'])) {
            return new MechanismResult(null, null, null, $d['confidence'], 'no_match',
                explanation: ($d['heading'] !== null
                    ? "Recalled heading {$d['heading']} — not an active XİF MN heading, abstaining. ".(string) $d['reason']
                    : ($d['reason'] ?? 'Model could not identify the item.')),
                model: $model, usage: $usage);
        }

        return new MechanismResult(
            matchedCode: $d['heading'], // 4-digit HS heading
            catalogId: null,
            kind: 'good',
            confidence: $d['confidence'],
            status: $status,
            explanation: (string) ($d['reason'] ?? ''),
            model: $model,
            usage: $usage,
        );
    }

    /**
     * Parse {heading, kind, confidence, reason} from the model output.
     *
     * @return array{heading: ?string, kind: ?string, confidence: ?float, reason: ?string}
     */
    private function parseHeading(string $content): array
    {
        $content = (string) preg_replace('#<think>.*?</think>#is', '', $content);
        $d = null;
        if (preg_match_all('/\{[^{}]*\}/s', $content, $m) && $m[0] !== []) {
            foreach (array_reverse($m[0]) as $j) {
                $try = json_decode($j, true);
                if (is_array($try) && (array_key_exists('heading', $try) || array_key_exists('kind', $try))) {
                    $d = $try;
                    break;
                }
            }
        }
        $d ??= [];
        $kind = strtolower(trim((string) ($d['kind'] ?? '')));
        $digits = preg_replace('/\D+/', '', (string) ($d['heading'] ?? ''));
        $reason = trim((string) ($d['reason'] ?? ''));

        return [
            'heading' => strlen((string) $digits) >= 4 ? mb_substr((string) $digits, 0, 4) : null,
            'kind' => in_array($kind, ['good', 'service'], true) ? $kind : null,
            'confidence' => isset($d['confidence']) ? round((float) $d['confidence'], 3) : null,
            'reason' => $reason !== '' ? $reason : null,
        ];
    }

    /** A 4-digit heading is trusted only if it is a real active catalog position. */
    private function isRealHeading(string $heading): bool
    {
        return CatalogCode::where('position', $heading)->where('is_active', true)->exists();
    }

    private function prompt(string $mode = 'code'): string
    {
        if ($mode === 'heading') {
            return <<<'PROMPT'
            You are an expert in Azerbaijan's XİF MN customs nomenclature (aligned with the
            HS / ТН ВЭД code system). You receive ONE line item from an Azerbaijani
            e-invoice. Identify WHAT the item actually is, then give the single most likely
            4-DIGIT HS HEADING (chapter+heading, e.g. "8413" or "3004") — NOT a full code.
            - FIRST decide GOOD vs SERVICE. A physical good handed over is a GOOD. Labour /
              work on or with a thing — repair "(təmiri)" / installation "quraşdırılması" /
              maintenance / transport / a fee — is a SERVICE: set "kind":"service" (no
              heading needed). A spare part supplied on its own, no action, is a GOOD.
            - The text is Azerbaijani and often noisy (brands, sizes, transliteration,
              dropped diacritics). Read the head-noun; ignore size/brand noise.
            - Identify the item from your OWN knowledge — category, active ingredient,
              material. A 4-digit heading is something you can recall reliably: give your
              single best heading.
            - Only if you genuinely cannot tell what it is, set "heading": null. Do NOT
              invent a heading for an unintelligible item.

            Respond with strict JSON only (no extra keys):
            {"heading": "<4 digits or null>", "kind": "good|service", "confidence": 0.0, "reason": "short"}
            PROMPT;
        }

        return <<<'PROMPT'
        You are an expert in Azerbaijan's XİF MN customs nomenclature (aligned with the
        HS / ТН ВЭД code system). You receive ONE line item from an Azerbaijani
        e-invoice. Identify WHAT the item actually is, then give the single most likely
        10-digit XİF MN code with a one-line reason.
        - FIRST decide what the line invoices: a physical GOOD handed over, or a SERVICE /
          labour performed on or with a thing — repair, installation, maintenance,
          transport, a fee. If a physical object is named but the point of the line is an
          ACTION on it (a trailing "(təmiri)" / "quraşdırılması" / "montajı" / "ремонт"),
          it IS that service — code it in the services chapter (99), not as the object;
          the object only says what the work is on. A spare part supplied on its own, with
          no action, stays a GOOD. Settle this before choosing a code.
        - The text is Azerbaijani and often noisy (brands, sizes, transliteration,
          dropped diacritics). For a good, read the head-noun; ignore size noise.
        - Identify the item from your OWN knowledge — its category, active ingredient,
          material. If a brand is familiar, use what you know it is; do not guess wildly.
        - Only if you genuinely cannot tell what it is (truly garbled token, or a bare
          brand you do not recognise), return "code": null. Do NOT invent a code for an
          unintelligible item.
        - Give a FULL 10-digit code, digits only.

        Respond with strict JSON only (no extra keys):
        {"code": "<10 digits or null>", "confidence": 0.0, "reason": "short"}
        PROMPT;
    }
}
