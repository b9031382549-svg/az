<?php

namespace App\Services\Translate;

use App\Models\ItemTranslation;
use App\Services\Llm\OpenRouterClient;
use App\Support\LlmLog;
use Throwable;

/**
 * Translates uploaded item names (usually Azerbaijani) into English and Russian
 * for display, caching every result in the item_translations dictionary so the
 * same item is never translated twice. Display-only — retrieval/matching always
 * use the original text.
 */
class ItemTranslator
{
    public function __construct(private readonly OpenRouterClient $llm) {}

    /**
     * Ensure a translation row exists and is filled for an item. Returns the row
     * (or null if the text is blank). Idempotent: a row that already has both
     * languages is returned untouched — no LLM call. Concurrency-safe via the
     * unique source_hash (a racing insert resolves to the same row).
     */
    public function ensure(string $text, bool $force = false): ?ItemTranslation
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $hash = ItemTranslation::hashFor($text);

        $row = ItemTranslation::firstOrCreate(
            ['source_hash' => $hash],
            ['source_text' => $text],
        );

        // Already translated (both languages present) — nothing to do, unless a
        // re-translation is forced (e.g. to replace poor earlier translations).
        if (! $force && ($row->en ?? '') !== '' && ($row->ru ?? '') !== '') {
            return $row;
        }

        try {
            [$en, $ru] = $this->translate($text);
            $row->forceFill([
                'en' => $en !== '' ? $en : null,
                'ru' => $ru !== '' ? $ru : null,
            ])->save();
        } catch (Throwable $e) {
            // Leave en/ru null — display falls back to the original text and a
            // later backfill/retry can fill it. Never break the caller.
            LlmLog::record(
                'translate_item', (string) config('services.openrouter.model'),
                ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
                0, 'error', null, [], null, $e->getMessage(),
                ['item' => mb_substr($text, 0, 120)],
            );
        }

        return $row;
    }

    /**
     * One translation LLM call. Returns [en, ru].
     *
     * @return array{0: string, 1: string}
     */
    private function translate(string $text): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->prompt()],
            ['role' => 'user', 'content' => $text],
        ];

        $response = $this->llm->jsonWithUsage($messages, [
            'model' => (string) config('classify.translate_model'),
        ]);

        LlmLog::record(
            'translate_item', $response['model'], $response['usage'], $response['latency_ms'] ?? 0,
            'ok', $response['raw'] ?? null, $messages, null, null,
            ['item' => mb_substr($text, 0, 120)],
        );

        $d = $response['data'];

        return [trim((string) ($d['en'] ?? '')), trim((string) ($d['ru'] ?? ''))];
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
        You translate a single e-invoice line item from Azerbaijani into English
        and Russian, for display in a UI. The text may contain brand names, model
        numbers, sizes, units and barcodes.

        Rules:
        - Translate EVERY descriptive Azerbaijani word: the product type, flavour,
          ingredient and attribute. Examples: çiyələkli = strawberry / клубничный;
          kişmişli = with raisins / с изюмом; küncütlü = with sesame / с кунжутом;
          ballı = with honey / с мёдом; çörək = bread; bulka = bun / булочка;
          çörəkçi = bakery / пекарня.
        - Keep UNCHANGED only genuine proper-noun brand names and alphanumeric
          codes/model numbers, plus sizes and units (transliterate a brand only if
          that is its conventional spelling).
        - If you do not know a word, transliterate it literally — NEVER replace it
          with a different ingredient, flavour or meaning, and never leave a plain
          Azerbaijani word untranslated.
        - Do not add explanations, notes or extra words.
        - If the item is already in the target language, return it cleaned up.

        Respond with strict JSON only: {"en":"...","ru":"..."}
        PROMPT;
    }
}
