<?php

namespace App\Services\Classify;

use App\Models\ItemTranslation;
use App\Models\ProductBrief;
use App\Services\Llm\OpenRouterClient;
use App\Support\LlmLog;
use Throwable;

/**
 * Upfront "product brief": one strong-model call that UNDERSTANDS an item before
 * the broker descends the tree — what it fundamentally is, what it is for, and
 * what it is made of. It deliberately does NOT choose a code, chapter or section:
 * routing stays the rulebook's (HS cards) job. Its role is to replace the broker's
 * noisy canonical essence with a clean, honest description, and to surface two
 * signals the review gate keys off — decisive_axis and material.basis.
 *
 * Cached per (item, prompt version) and reused everywhere. Broker-LOCAL: the
 * vector mechanism never sees it, so a wrong brief only sways the broker and a
 * broker↔vector disagreement still becomes a conflict routed to a human.
 */
class ProductBriefService
{
    public function __construct(private readonly OpenRouterClient $llm) {}

    /**
     * Return the normalized brief array, or null when briefs are disabled or the
     * item cannot be understood (the caller must degrade to its own essence).
     *
     * @return array<string, mixed>|null
     */
    public function brief(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || ! config('classify.broker.use_brief', true)) {
            return null;
        }

        $sourceHash = ItemTranslation::hashFor($text);
        $version = (string) config('classify.broker.brief_prompt_version', 'b1');

        $cached = ProductBrief::where('source_hash', $sourceHash)
            ->where('prompt_version', $version)
            ->first();
        if ($cached !== null) {
            return $cached->ok ? $cached->data : null;
        }

        try {
            $messages = [
                ['role' => 'system', 'content' => $this->prompt()],
                ['role' => 'user', 'content' => "ITEM: {$text}"],
            ];
            $response = $this->llm->jsonWithUsage($messages, ['model' => (string) config('classify.broker.brief_model', 'openai/gpt-4o')]);
            LlmLog::record('broker_brief', $response['model'], $response['usage'], $response['latency_ms'] ?? 0,
                'ok', $response['raw'] ?? null, $messages, 'broker', null, ['item' => mb_substr($text, 0, 80)]);

            $brief = $this->normalize($response['data']);
            $ok = $brief['identity'] !== '';

            ProductBrief::create([
                'source_hash' => $sourceHash,
                'prompt_version' => $version,
                'identity' => $brief['identity'] !== '' ? $brief['identity'] : null,
                'purpose' => $brief['purpose'] !== '' ? $brief['purpose'] : null,
                'function_class' => $brief['function_class'],
                'material_value' => $brief['material']['value'],
                'material_basis' => $brief['material']['basis'],
                'decisive_axis' => $brief['decisive_axis'],
                'confidence' => $brief['confidence'],
                'ok' => $ok,
                'data' => $brief,
                'model' => $response['model'],
                'usage' => $response['usage'],
            ]);

            return $ok ? $brief : null;
        } catch (Throwable) {
            return null; // graceful — the broker falls back to its canonical essence
        }
    }

    /**
     * Coerce the raw model JSON into a stable brief shape (never trust the model
     * to return every field, or the enums exactly).
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function normalize(array $d): array
    {
        $material = is_array($d['material'] ?? null) ? $d['material'] : [];
        $basis = $this->oneOf($material['basis'] ?? null, ['stated', 'typical', 'unknown'], 'unknown');
        // A material name is short; cap it so a verbose/hallucinated value can't bloat
        // the row (defence-in-depth on top of the text() column).
        $value = mb_substr(trim((string) ($material['value'] ?? '')), 0, 200);

        return [
            'identity' => trim((string) ($d['identity'] ?? '')),
            'purpose' => trim((string) ($d['purpose'] ?? '')),
            'function_class' => $this->oneOf($d['function_class'] ?? null, [
                'instrument', 'appliance', 'part', 'accessory', 'article', 'set',
                'packaging', 'consumable', 'foodstuff', 'chemical', 'raw_material',
                'service', 'other',
            ], 'other'),
            'material' => [
                'value' => $value !== '' && $value !== 'null' ? $value : null,
                'basis' => $basis,
            ],
            'decisive_axis' => $this->oneOf($d['decisive_axis'] ?? null, ['function', 'origin', 'material', 'identity'], 'identity'),
            'confidence' => round((float) ($d['confidence'] ?? 0), 3),
        ];
    }

    /** @param array<int, string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $default): string
    {
        $value = is_string($value) ? mb_strtolower(trim($value)) : '';

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
        You identify what a product fundamentally IS, so a downstream customs
        classifier can place it. You do NOT choose a code, chapter or category —
        you produce a clean, complete UNDERSTANDING of the item: what it is, what it
        is for, and what it is made of. Describe honestly; never fabricate.

        READING NOISY INPUT (do this FIRST). The item is a real Azerbaijani/Russian
        invoice line: brand-first, full of model/article codes, barcodes, dimensions,
        gram-weights, pack counts, colours and packaging words, with dropped
        diacritics and mixed scripts.
        - Find the HEAD-NOUN — the common noun naming the article (şpris, ton balığı,
          salfet, qrelka, kateter). Base the understanding on it. Ignore model/article
          codes, barcodes, dimensions/quantities (5ml, 23G, 24X150GR, № 1, 1*24),
          colours and packaging words. Treat the brand per the BRANDS rule below — it
          is usually noise, but a recognized maker can settle an ambiguous noun.
        - BUT keep IDENTITY-BEARING tokens that pin the KIND of article (a lamp cap
          code E27/E14/GU10 = a screw/pin bulb; a clinical needle-gauge in a medical
          context).
        - Normalise script/diacritics mentally: Latin letters may stand in for AZ
          diacritics (e↔ə, s↔ş, c↔ç, g↔ğ, i↔ı, u↔ü, o↔ö); Cyrillic letters mix into
          Latin words. SPRIS/shpris→şpris (syringe), iyne→iynə (needle),
          Kepenek/Kəpənək iynə→butterfly (winged) infusion needle. NEVER lower
          confidence merely because diacritics are missing.
        - Transliterated Russian is common: salfetki=napkins/wipes, losos=salmon,
          skumbriya=mackerel, grelka/qrelka=hot-water bottle, shprits=syringe,
          sprintsovka=enema bulb, v masle=in oil, v tomate=in tomato.
        - BRANDS: the product noun always leads; the brand is EVIDENCE, used two ways.
          (a) A globally RECOGNIZED manufacturer disambiguates a GENERIC or ambiguous
          noun by its domain — B.Braun / BD / Fresenius / Braun (medical) → a medical
          device; Bosch / Makita → tools; Nivea / L'Oréal → cosmetics — even when the
          brand is transliterated or MISSPELLED (B&Braumann = B.Braun). Use it to
          resolve WHAT the item is: a "system with filter" from B.Braun is an IV
          infusion set, not a plumbing hose. (b) An UNKNOWN or local/private label
          (Dardanel, Dr.Para, Baysmed, XONCA…) tells you nothing — do not guess what it
          sells, and never let a brand that merely resembles a word (Inci=pearl)
          override a clear product noun. If ONLY a brand/code remains with no product
          noun, set identity to the brand verbatim and confidence ≤ 0.3.
        - AZERBAIJANI DOMAIN TERMS — treat these as the MEANING, do not guess around
          them: imalə = enema (irrigator / enema bulb); sistem / система = an IV
          infusion / transfusion SET (not a generic "system"); şlanq = tubing/hose;
          iynə = needle; şpris = syringe; sarğı = dressing/bandage; tənzif = gauze;
          pambıq = cotton (wool); flaster / plastır = adhesive plaster; əlcək = glove;
          maska = mask; damcı = drops; məhlul = solution; birdəfəlik = single-use;
          steril = sterile; venöz / venadaxili = intravenous; sərt uclu = hard-tipped;
          yumşaq uclu = soft-tipped.
        - MEDICAL SUPPLIES are easily misread as industrial/plumbing. With kateter,
          kanül, venöz, steril, filtirli, imalə, or a medical brand (B.Braun/BD) the
          item is a MEDICAL DEVICE — NOT a hose, valve, can, machine part or emery
          board. Name it precisely in identity (e.g. "IV infusion set with filter",
          "enema bulb with hard tip").
        - NON-GOODS: a service or labour (xidmət, quraşdırılması, təmir, daşınma), an
          air ticket, a fee or a document is not a good — set function_class "service"
          and confidence 0.0.
        - If the whole string is unreadable/garbled, say so and set confidence ≤ 0.2.

        WHAT TO OUTPUT (all free-text fields in ENGLISH; translate/transliterate the
        AZ/RU source):
        - identity: the canonical common noun, singular, plus what it fundamentally is
          and DOES — e.g. "rubber hot-water bottle for applying heat", "disposable
          hypodermic syringe", "winged (butterfly) infusion needle". Do NOT slap on a
          category label like "medical device"; describe the actual article and how it
          works. No dimensions, pack size or barcodes.
        - purpose: what the item is for / how it is used, one phrase.
        - function_class: one of — instrument | appliance | part | accessory | article
          | set | packaging | consumable | foodstuff | chemical | raw_material |
          service | other. "article" = a plain finished object with no operative
          mechanism (a bottle, a case, a hot-water bottle).
        - material.value: the material(s) it is made of, in English, or null if
          genuinely unknown or irrelevant to what it is.
        - material.basis: how you know the material —
            "stated"  = the DECIDING material is written in the item text. A material
                        word describing a sub-component or used as an adjective
                        (rezin porşenli = rubber-PLUNGER, plastik qapaqlı) is NOT the
                        deciding material → not "stated".
            "typical" = not written, but essentially every product of this exact
                        identity is that material (>90% of the market, e.g. a
                        hot-water bottle is rubber, cotton wool is cotton).
            "unknown" = otherwise.
        - decisive_axis: what MOST decides where this item is classified —
            "function" (defined by what it does: a syringe, a machine, an appliance),
            "origin"   (a raw animal/plant/food/mineral),
            "material" (a plain article whose classification would CHANGE with its
                        material: a hot-water bottle, a bottle, a case, apparel), or
            "identity" (a specific named article or set: a first-aid kit, furniture).
        - confidence: 0..1 — how sure you are of the IDENTITY (what the thing is), NOT
          where it is filed. 0.9+ head-noun clear; 0.4–0.7 head-noun found but
          genuinely ambiguous; ≤ 0.3 only a brand, garbled text, or a service.

        Do NOT invent facts. If unsure, lower confidence and mark basis "unknown".

        Respond with EXACTLY ONE JSON object and nothing else — no markdown, no text
        before or after:
        {"identity":"","purpose":"","function_class":"article","material":{"value":null,"basis":"stated|typical|unknown"},"decisive_axis":"function|origin|material|identity","confidence":0.0}

        EXAMPLES (follow this shape and calibration):
        "Şpris 5ml 23GХ32 rezin porşenli (B.Braun)" → {"identity":"disposable hypodermic syringe","purpose":"inject or withdraw fluid","function_class":"instrument","material":{"value":null,"basis":"unknown"},"decisive_axis":"function","confidence":0.92}
        "Qrelka (isidici) 2000 ml (Dr.Para)" → {"identity":"rubber hot-water bottle for applying heat","purpose":"warm the body / heat therapy","function_class":"article","material":{"value":"rubber","basis":"typical"},"decisive_axis":"material","confidence":0.8}
        "Kəpənək iynə B.Braun" → {"identity":"winged (butterfly) infusion needle","purpose":"venous access for infusion","function_class":"instrument","material":{"value":null,"basis":"unknown"},"decisive_axis":"function","confidence":0.85}
        "Sistem şlanq filtirli (B&Braumann) № 1" → {"identity":"IV infusion set with filter","purpose":"deliver fluids/medication intravenously","function_class":"instrument","material":{"value":null,"basis":"unknown"},"decisive_axis":"function","confidence":0.85}
        "Inci salfet 100 əd" → {"identity":"paper napkins","purpose":"wiping","function_class":"consumable","material":{"value":"paper","basis":"typical"},"decisive_axis":"material","confidence":0.8}
        "DARDANEL TON 24X150GR TOMAT SOSLU" → {"identity":"tinned tuna in tomato sauce","purpose":"food","function_class":"foodstuff","material":{"value":null,"basis":"unknown"},"decisive_axis":"origin","confidence":0.93}
        "Yükdaşıma xidməti" → {"identity":"freight/delivery service","purpose":"transport","function_class":"service","material":{"value":null,"basis":"unknown"},"decisive_axis":"identity","confidence":0.0}
        PROMPT;
    }
}
