<?php

namespace App\Services\Classify;

use App\Models\Classification;
use App\Models\LlmUsage;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Support\Arr;
use Throwable;

class ClassifierService
{
    public function __construct(
        private readonly CatalogRetriever $retriever,
        private readonly OpenRouterClient $llm,
    ) {}

    /**
     * Classify a free-text line item: good/service + best XİF MN code, with a
     * confidence-based review status.
     *
     * @return array<string, mixed>
     */
    public function classify(string $text): array
    {
        $text = trim($text);
        $result = [
            'text' => $text,
            'kind' => null,
            'code' => null,
            'catalog_id' => null,
            'name' => null,
            'confidence' => null,
            'semantic_sim' => null,
            'status' => 'no_match',
            'reason' => null,
            'candidates' => [],
            'usage' => null,
            'error' => null,
        ];

        if ($text === '') {
            $result['error'] = 'Empty item.';
            $result['status'] = 'error';

            return $result;
        }

        try {
            [$retrievalText, $expandUsage] = $this->expandForRetrieval($text);

            $candidates = $this->retriever->candidates($retrievalText, (int) config('classify.candidates'));
            if (empty($candidates)) {
                $result['usage'] = $expandUsage;
                $result['reason'] = 'No catalog candidates found.';

                return $result;
            }

            $result['candidates'] = array_map(fn ($c) => [
                'code' => $c->code, 'kind' => $c->kind, 'name' => $c->name,
                'score' => $c->score, 'semantic_sim' => $c->semantic_sim ?? null,
            ], array_slice($candidates, 0, 10));

            $picked = $this->rerank($text, $candidates);
            $this->logUsage($picked['usage'], $picked['model'], $text, 'rerank');

            $result['usage'] = $this->sumUsage($expandUsage, $picked['usage']);

            $match = $picked['code']
                ? Arr::first($candidates, fn ($c) => $c->code === $picked['code'])
                : null;

            if (! $match) {
                $result['kind'] = $picked['kind'];
                $result['confidence'] = round((float) $picked['confidence'], 3);
                $result['reason'] = $picked['reason'] ?? 'No confident match among candidates.';

                return $result;
            }

            $confidence = round((float) $picked['confidence'], 3);
            $semanticSim = $match->semantic_sim ?? null;

            $result['kind'] = $match->kind; // authoritative: derived from the code (99 => service)
            $result['code'] = $match->code;
            $result['catalog_id'] = $match->id;
            $result['name'] = $match->name;
            $result['confidence'] = $confidence;
            $result['semantic_sim'] = $semanticSim;
            $result['reason'] = $picked['reason'] ?? null;

            // Auto-confirm needs BOTH the model's confidence AND retrieval (cosine)
            // agreement — an over-confident pick with weak semantic backing goes
            // to review instead of being auto-confirmed.
            $confident = $confidence >= (float) config('classify.auto_confirm');
            $backed = $semanticSim !== null && $semanticSim >= (float) config('classify.min_semantic');
            $result['status'] = ($confident && $backed) ? 'auto_confirmed' : 'needs_review';
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
            $result['status'] = 'error';
        }

        return $result;
    }

    public function record(array $result, ?string $batch = null): Classification
    {
        return Classification::create([
            'source_text' => $result['text'],
            'kind' => $result['kind'],
            'catalog_id' => $result['catalog_id'],
            'matched_code' => $result['code'],
            'confidence' => $result['confidence'],
            'status' => $result['status'],
            'candidates' => $result['candidates'],
            'explanation' => $result['reason'],
            'batch' => $batch,
        ]);
    }

    /**
     * @param  array<int, object>  $candidates
     * @return array{kind:?string, code:?string, confidence:float, reason:?string, usage:array<string,int>, model:string}
     */
    private function rerank(string $text, array $candidates): array
    {
        $lines = [];
        foreach (array_values($candidates) as $i => $c) {
            $lines[] = ($i + 1).". code={$c->code} [{$c->kind}] ".mb_substr($c->name, 0, 180);
        }
        $list = implode("\n", $lines);

        $response = $this->llm->jsonWithUsage([
            ['role' => 'system', 'content' => $this->prompt()],
            ['role' => 'user', 'content' => "ITEM: {$text}\n\nCANDIDATES:\n{$list}"],
        ], ['model' => config('services.openrouter.classify_model')]);

        $d = $response['data'];

        return [
            'kind' => $d['kind'] ?? null,
            'code' => isset($d['code']) && $d['code'] !== null && $d['code'] !== '' ? (string) $d['code'] : null,
            'confidence' => (float) ($d['confidence'] ?? 0),
            'reason' => $d['reason'] ?? null,
            'usage' => $response['usage'],
            'model' => $response['model'],
        ];
    }

    /**
     * Normalize a noisy item into a canonical product description (via the cheap
     * default model) and append it to the original, so brand/coded names still
     * retrieve the right candidates. Returns [retrievalText, usage].
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private function expandForRetrieval(string $text): array
    {
        $zero = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

        if (! config('classify.expand_query', true)) {
            return [$text, $zero];
        }

        try {
            $response = $this->llm->jsonWithUsage([
                ['role' => 'system', 'content' => $this->expandPrompt()],
                ['role' => 'user', 'content' => $text],
            ]);

            $this->logUsage($response['usage'], $response['model'], $text, 'expand');

            $description = trim((string) ($response['data']['description'] ?? ''));
            $retrievalText = $description !== '' ? $description.' '.$text : $text;

            return [$retrievalText, $response['usage']];
        } catch (Throwable) {
            return [$text, $zero]; // graceful: fall back to the raw item
        }
    }

    /**
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     * @return array<string, int>
     */
    private function sumUsage(array $a, array $b): array
    {
        return [
            'prompt_tokens' => ($a['prompt_tokens'] ?? 0) + ($b['prompt_tokens'] ?? 0),
            'completion_tokens' => ($a['completion_tokens'] ?? 0) + ($b['completion_tokens'] ?? 0),
            'total_tokens' => ($a['total_tokens'] ?? 0) + ($b['total_tokens'] ?? 0),
        ];
    }

    private function logUsage(array $usage, string $model, string $text, string $purpose): void
    {
        LlmUsage::create([
            'purpose' => $purpose,
            'model' => $model,
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? 0,
            'meta' => ['item' => mb_substr($text, 0, 120)],
        ]);
    }

    private function expandPrompt(): string
    {
        return <<<'PROMPT'
        You normalize a noisy e-invoice line item into a canonical product or
        service description for catalogue lookup. The item is usually Azerbaijani
        and may contain brand names, article numbers, sizes and packaging.

        Output what the item fundamentally IS, in 2-6 words IN AZERBAIJANI,
        dropping brand names, article numbers and sizes. Examples:
        - "5337 ZEWA DELUXE BRT 8 3PLY CAMOMILE" -> "tualet kağızı"
        - "Şpris 5ml 23G BLİSSET" -> "tibbi şpris"
        - "PANNAKOTA" -> "süd deserti pannakotta"
        - "Su (Pizza Hut)" -> "içməli su"

        Respond with strict JSON only: {"description": "..."}
        PROMPT;
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
        You are a customs classification expert for Azerbaijan's XİF MN nomenclature
        (the national TN VED / Harmonized System). You receive a product or service
        description (usually in Azerbaijani) and a list of candidate 10-digit codes
        retrieved from the official registry.

        Pick the SINGLE best matching code from the candidates.

        Rules:
        - Codes that start with "99" are SERVICES; all other codes are GOODS.
        - Choose only from the provided candidates. Do not invent codes.
        - Classify by what the item IS (its function / purpose), NOT merely by the
          material it is made of. E.g. a syringe with a rubber plunger is a medical
          syringe, not a rubber article; a plastic water bottle is a beverage
          container, not a plastics product.
        - Prefer the most specific code that fits the item's actual purpose.
        - If none of the candidates is a reasonable match, set "code" to null.
        - Calibrate "confidence" (0..1) honestly: use > 0.85 only when a candidate
          clearly and specifically matches the item; if you can only find a generic
          or material-based fallback, keep confidence below 0.7.

        Respond with a strict JSON object only:
        {"kind":"good|service","code":"<chosen code or null>","confidence":0.0,"reason":"<short justification>"}
        PROMPT;
    }
}
