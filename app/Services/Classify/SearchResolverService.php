<?php

namespace App\Services\Classify;

use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Models\RubricatorNode;
use App\Services\Llm\OpenRouterClient;
use App\Support\LlmLog;
use Throwable;

/**
 * The LAST resort when the three mechanisms diverge (Consensus → 'conflict'). A
 * thinking model WITH web search identifies the item (looking up unfamiliar
 * brands/drugs online) and returns just the 4-DIGIT HS heading + a self-reported
 * confidence. Confident (>= min_confidence) and a real heading → the item resolves to
 * that heading ('ai_resolved'); otherwise it stays 'conflict' for a human. Either way
 * the attempt is recorded as a `mechanism='search'` trace row so the decision/review
 * page shows what the search found. Runs from SearchResolveJob, once per item.
 */
class SearchResolverService
{
    public function __construct(private readonly OpenRouterClient $llm, private readonly SearchCache $cache) {}

    /**
     * Resolve one conflicting item via web search. Writes the 'search' trace row and,
     * when confident, flips the item to 'ai_resolved' at the 4-digit heading. Never
     * clobbers a human/terminal decision that landed while queued.
     */
    public function resolve(ClassificationItem $item): void
    {
        $text = trim((string) $item->source_text);
        $model = (string) config('classify.search_resolver.model', 'deepseek/deepseek-v4-flash:online');
        if ($text === '') {
            return;
        }

        $d = $this->ask($text, $model);
        if ($d === null) {
            // Search unavailable/timed out — leave the item as a conflict for a human,
            // recording that the search step ran but could not settle it.
            $this->trace($item, null, null, null, 'no_match', 'Search resolver unavailable.', $model);

            return;
        }

        $sourceNote = $this->sourceNote($d['sources'] ?? []);
        $reason = trim((string) ($d['reason'] ?? '')).$sourceNote;

        // A resolvable heading is either a real 4-digit HS heading in the catalog, or
        // the bare "99" service level (chapter 99 has no single heading row).
        [$heading, $kind] = $this->validate($d['heading'] ?? null, (string) ($d['kind'] ?? ''));
        $confidence = $d['confidence'];
        $min = (float) config('classify.search_resolver.min_confidence', 0.8);

        $confidentEnough = $heading !== null && $confidence !== null && $confidence >= $min;

        // Trace row (one per item, mechanism='search') — always written so the decision
        // page shows the search verdict + citations even when it didn't settle it.
        $this->trace(
            $item,
            $heading,
            $kind,
            $confidence,
            $confidentEnough ? 'auto_confirmed' : 'needs_review',
            $reason !== '' ? $reason : ($heading !== null ? 'Identified via web search.' : 'Search could not confidently identify the item.'),
            $model,
            $this->headingName($heading),
        );

        if (! $confidentEnough) {
            return; // stays 'conflict' → human review
        }

        // Apply at the 4-DIGIT heading (final_catalog_id null — no exact catalog row).
        // Conditional update: only a still-divergent item flips, so a human confirm/
        // reject that landed while this job ran is never overwritten.
        ClassificationItem::whereKey($item->id)
            ->whereIn('resolution', ['conflict', 'review'])
            ->update([
                'resolution' => 'ai_resolved',
                'final_code' => $heading,
                'final_catalog_id' => null,
                'kind' => $kind,
            ]);
    }

    /** One search call → parsed {heading, kind, confidence, reason, sources}, or null. */
    private function ask(string $text, string $model): ?array
    {
        // Cache read FIRST: lookup() is fully error-isolated (returns null on any fault),
        // so a cache miss/error simply falls through to the live call below — the resolve
        // path is never blocked. A hit skips the slow paid `:online` call entirely.
        $cached = $this->cache->lookup($model, $text);
        if ($cached !== null) {
            // Log the hit as its own zero-cost row (real spend = 0); the avoided tokens
            // go to meta so savings stay measurable. Use the response's resolved model so
            // the log matches the original live call.
            LlmLog::record('search_resolve', (string) ($cached['model'] ?? $model), [], 0, 'cache',
                $cached['content'] ?? null, [], 'search_resolver', null,
                ['item' => mb_substr($text, 0, 80), 'cache_hit' => true,
                    'saved_total_tokens' => (int) ($cached['usage']['total_tokens'] ?? 0)]);

            $d = $this->parse((string) ($cached['content'] ?? ''));
            if ($d !== null) {
                $d['sources'] = $cached['annotations'] ?? [];
            }

            return $d;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->prompt()],
            ['role' => 'user', 'content' => "ITEM: {$text}"],
        ];

        try {
            $resp = $this->llm->complete($messages, [
                'model' => $model,
                'timeout' => (int) config('classify.search_resolver.timeout', 180),
            ]);
            LlmLog::record('search_resolve', $resp['model'] ?? $model, $resp['usage'] ?? [], 0, 'ok',
                $resp['content'] ?? null, $messages, 'search_resolver', null, ['item' => mb_substr($text, 0, 80)]);

            $d = $this->parse((string) ($resp['content'] ?? ''));
            if ($d !== null) {
                $d['sources'] = $resp['annotations'] ?? []; // web citations, if it searched

                // Cache ONLY a confident, catalog-valid answer — never a no_match / null /
                // low-confidence result, so a one-time "couldn't identify" (the web may do
                // better later) is not frozen forever. Mirrors resolve()'s acceptance test.
                [$validHeading] = $this->validate($d['heading'] ?? null, (string) ($d['kind'] ?? ''));
                $min = (float) config('classify.search_resolver.min_confidence', 0.8);
                if ($validHeading !== null && ($d['confidence'] ?? 0) >= $min) {
                    $this->cache->store($model, $text, [
                        'content' => (string) ($resp['content'] ?? ''),
                        'usage' => $resp['usage'] ?? [],
                        'model' => $resp['model'] ?? $model,
                        'annotations' => $resp['annotations'] ?? [],
                    ]);
                }
            }

            return $d;
        } catch (Throwable) {
            // Slow reasoning + search can time out — abstain, never block the queue.
            return null;
        }
    }

    /**
     * A returned heading is valid only if it is a real 4-digit HS heading in the
     * catalog, or the "99" service sentinel. Returns [heading|null, kind].
     *
     * @return array{0: ?string, 1: string}
     */
    private function validate(?string $heading, string $kind): array
    {
        $kind = $kind === 'service' ? 'service' : 'good';

        if ($heading === null) {
            return [null, $kind];
        }

        if ($heading === '99') {
            return ['99', 'service']; // service level — no position row exists for it
        }

        $exists = CatalogCode::where('position', $heading)->where('is_active', true)->exists();

        return $exists ? [$heading, $kind] : [null, $kind];
    }

    /** The rubricator display name for a 4-digit heading (or null / "service level"). */
    private function headingName(?string $heading): ?string
    {
        if ($heading === null) {
            return null;
        }
        if ($heading === '99') {
            return 'service level';
        }

        return RubricatorNode::where('code', $heading)->value('title');
    }

    /** Persist / update the single `mechanism='search'` trace row for this item. */
    private function trace(ClassificationItem $item, ?string $code, ?string $kind, ?float $confidence, string $status, string $reason, string $model, ?string $headingName = null): void
    {
        $item->results()->updateOrCreate(
            ['mechanism' => 'search'],
            [
                'matched_code' => $code,
                'catalog_id' => null,
                'kind' => $kind,
                'status' => $status,
                'confidence' => $confidence,
                'candidates' => [],
                'explanation' => $reason,
                'model' => $model,
                'trace' => ['heading' => $code, 'heading_name' => $headingName, 'confidence' => $confidence],
            ],
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
     * Extract {heading, kind, confidence, reason} — a reasoning model emits
     * chain-of-thought (with stray braces), so strip <think> and take the LAST JSON
     * object. The code is collapsed to its 4-digit heading (or the "99" service level).
     *
     * @return array{heading: ?string, kind: string, confidence: ?float, reason: ?string}|null
     */
    private function parse(string $content): ?array
    {
        $content = (string) preg_replace('#<think>.*?</think>#is', '', $content);

        // Take the LAST brace-balanced object that actually decodes: a reasoning model
        // can echo stray '{' before the real JSON, which a greedy first-to-last span
        // would swallow into invalid JSON and drop an otherwise good answer.
        if (! preg_match_all('/\{(?:[^{}]|(?R))*\}/s', $content, $m) || $m[0] === []) {
            return null;
        }
        $d = null;
        for ($i = count($m[0]) - 1; $i >= 0; $i--) {
            $decoded = json_decode($m[0][$i], true);
            if (is_array($decoded)) {
                $d = $decoded;
                break;
            }
        }
        if (! is_array($d)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) ($d['heading'] ?? ''));
        $heading = match (true) {
            $digits === '99' => '99',                 // service level (chapter 99)
            mb_strlen((string) $digits) >= 4 => mb_substr((string) $digits, 0, 4),
            default => null,
        };
        $reason = trim((string) ($d['reason'] ?? ''));

        // Confidence must be a real 0..1 number. Anything out of scale (55, "high",
        // true) is UNKNOWN — never let it bypass the min_confidence gate and confidently
        // resolve a conflict; an unreadable confidence stays with a human.
        $conf = $d['confidence'] ?? null;
        $confidence = (is_numeric($conf) && (float) $conf >= 0 && (float) $conf <= 1)
            ? round((float) $conf, 3)
            : null;

        return [
            'heading' => $heading,
            'kind' => ($d['kind'] ?? '') === 'service' ? 'service' : 'good',
            'confidence' => $confidence,
            'reason' => $reason !== '' ? $reason : null,
        ];
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
        You are an expert in Azerbaijan's XİF MN customs nomenclature (aligned with the
        HS / ТН ВЭД system). Three automatic methods DISAGREED on ONE line item from an
        Azerbaijani e-invoice, so you are the tie-breaker. Identify WHAT the item actually
        is — use WEB SEARCH for any unfamiliar brand, drug, or product name to find its
        category / active ingredient / material — then give the single most likely
        4-DIGIT HS HEADING (position), NOT a full code.
        - FIRST decide what the line invoices: a physical GOOD handed over, or a SERVICE /
          labour performed (repair, installation, transport, a fee). If the point of the
          line is an ACTION on a thing (a trailing "(təmiri)" / "quraşdırılması" / "ремонт"),
          it is that SERVICE → chapter 99; return "heading": "99", "kind": "service".
        - The text is Azerbaijani and often noisy (brands, sizes, transliteration, dropped
          diacritics). For a good, read the head-noun; ignore size/quantity noise.
        - Give ONLY the 4-digit heading (e.g. "8471"), digits only — we do not need the
          deeper subheading.
        - Report a CONFIDENCE from 0.0 to 1.0 that this heading is correct. Be honest: if a
          web search could not tell what the item is, or several headings are equally
          plausible, give a LOW confidence — a human will then review it.
        - If you genuinely cannot identify the item even after searching, set
          "heading": null with a low confidence. Do NOT guess a heading you are unsure of.

        Respond with strict JSON only (no extra keys):
        {"heading": "<4 digits, or 99 for a service, or null>", "kind": "good|service", "confidence": 0.0, "reason": "short — what it is and why this heading"}
        PROMPT;
    }
}
