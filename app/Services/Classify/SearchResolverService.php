<?php

namespace App\Services\Classify;

use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Models\ClassificationResult;
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
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly SearchCache $cache,
        private readonly AnswerCacheService $memory,
        private readonly CatalogRetriever $retriever,
    ) {}

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

        // ── Flow v2: self-consistency ensemble chooser BEFORE the paid web search ──
        // Vote the grounded chooser over 3 paraphrases (raw / identity / az_reading) against
        // the vector shortlist. Agreement commits the voted heading and skips the web; a SPLIT
        // vote is a self-consistency abstain that falls through to the web resolver. In shadow
        // mode the verdict is recorded but the web answer is still served.
        if (config('classify.flow.ensemble_resolver')) {
            $ens = $this->ensemble($item, $text);
            if ($ens !== null) {
                $this->traceEnsemble($item, $ens);
                $agreed = in_array($ens['agreement'], ['unanimous', 'majority'], true) && $ens['answer'] !== null;
                if ($agreed && ! config('classify.flow.shadow')) {
                    $this->applyEnsemble($item, $ens);

                    return; // agreement → resolved locally, no paid web search
                }
                // shadow OR split → fall through to the web resolver below
            }
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
        $search = $this->trace(
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
        $applied = ClassificationItem::whereKey($item->id)
            ->whereIn('resolution', ['conflict', 'review'])
            ->update([
                'resolution' => 'ai_resolved',
                'final_code' => $heading,
                'final_catalog_id' => null,
                'kind' => $kind,
            ]);

        // Write GROUNDED answers back into memory — only when this heading overlaps with
        // one of the original pre-conflict vector/broker/direct candidates (see
        // AnswerCacheService::promoteGroundedSearch()); never for a hard/ungrounded case.
        if ($applied === 1) {
            $authoritative = Consensus::computeAuthoritative(
                (array) config('classify.mechanisms.enabled', ['vector']),
                (array) config('classify.mechanisms.shadow', []),
            );
            $authResults = $item->results()->whereIn('mechanism', $authoritative)->get();
            $this->memory->promoteGroundedSearch($item, $search, $authResults);
        }
    }

    /**
     * Flow v2 — the self-consistency ensemble. Runs the grounded chooser over three
     * paraphrases (raw text / brief identity / brief az_reading) against the vector
     * mechanism's shortlist and votes. Returns the verdict, or null when it cannot run
     * (no vector shortlist, or fewer than two distinct groundings for a meaningful vote).
     *
     * @return array{answer: ?string, kind: string, agreement: string, picks: array<int, string>, groundings: array<int, string>, shortlist: array<int, string>}|null
     */
    private function ensemble(ClassificationItem $item, string $text): ?array
    {
        // 1) WEB-grounded understanding — the search-free upstream brief hallucinates terse
        //    AZ tokens ("çelik dübel" → "steel needle"), so the resolver does its own
        //    web-backed identification here. This is the crux; without it the rest is noise.
        $u = $this->understand($text);
        if ($u === null) {
            return null; // could not understand → leave it to the web resolver below
        }

        // 2) Build a FRESH shortlist from that understanding (identity + synonyms + raw),
        //    fused via CatalogRetriever — so the candidates reflect what the item ACTUALLY is,
        //    not the noisy raw tokens.
        $k = max(1, (int) config('classify.flow.ensemble.shortlist_k', 12));
        $queries = array_values(array_filter(array_merge([$u['identity']], $u['synonyms'], [$text])));
        $shortlist = [];
        $seen = [];
        try {
            foreach ($this->retriever->candidates($queries, max($k, 24)) as $row) {
                $h = mb_substr((string) $row->code, 0, 4);
                if ($h === '' || isset($seen[$h])) {
                    continue;
                }
                $seen[$h] = true;
                $shortlist[] = $h;
                if (count($shortlist) >= $k) {
                    break;
                }
            }
        } catch (Throwable) {
            return null; // retrieval unavailable → fall through to the web resolver
        }
        if ($shortlist === []) {
            return null;
        }

        // 3) Three distinct "what it is" framings for the self-consistency vote; dedupe so
        //    identical groundings cannot fake agreement.
        $groundings = [];
        foreach ([$text, $u['identity'], $u['az_reading']] as $g) {
            $g = trim($g);
            if ($g !== '' && ! in_array($g, $groundings, true)) {
                $groundings[] = $g;
            }
        }
        if (count($groundings) < 2) {
            return null;
        }

        $list = '';
        foreach ($shortlist as $h) {
            $list .= '  '.$h.' - '.($this->headingName($h) ?? '')."\n";
        }

        $picks = [];
        foreach ($groundings as $g) {
            $picks[] = $this->ensembleChoose($g, $text, $list);
        }

        [$answer, $agreement] = $this->tally($picks, $shortlist);

        return [
            'answer' => $answer,
            'kind' => $answer === '99' ? 'service' : 'good',
            'agreement' => $agreement,
            'picks' => $picks,
            'groundings' => $groundings,
            'shortlist' => $shortlist,
            'understanding' => $u,
        ];
    }

    /**
     * Web-grounded identification of a terse Azerbaijani customs line: returns
     * {identity, az_reading, synonyms[]}, or null when it cannot be identified. Uses a
     * `:online` model with an AZ-customs prompt (transliterate first, prefer the physical
     * good over any brand collision) — the understanding that made the offline gains real.
     *
     * @return array{identity: string, az_reading: string, synonyms: array<int, string>}|null
     */
    private function understand(string $text): ?array
    {
        $sys = 'You identify ONE line item from an AZERBAIJANI CUSTOMS IMPORT declaration. Every item is an ordinary physical tradable GOOD imported into Azerbaijan by a commercial company — NEVER a video game, movie, book, song, mobile app, software, company or web service. The text is a terse invoice line: transliterated Azerbaijani or Russian, with abbreviations, units and typos.
RULES:
- Read tokens as Azerbaijani/Russian COMMERCIAL/COMMODITY words; transliterate FIRST. Examples: "kislord"=oxygen gas; "tut"=mulberry; "med"/"bal"=honey; "celik dubel"=steel wall plug/anchor; "zewa"=paper-hygiene brand (toilet paper/napkins); "kondes"=air conditioner.
- If a token collides with a brand, game, company, drug or media title, PREFER the physical-goods reading.
- When you look it up, treat it as an Azerbaijani import commodity (mentally append "Azerbaijan idxal gomruk"); do NOT accept a foreign game/app/company result.
- Use units and packaging as evidence: kg/l/m/qr/ed/rulon mean a physical good; note material.
- If you truly cannot tell, prefix identity with "uncertain:".
Output strict JSON only: {"identity":"<head-noun/type + material/function>","az_reading":"<a second, differently-phrased one-line reading>","synonyms":["<4-6 alt-names / analogous goods / category terms an HS catalog would use>"]}';

        try {
            $resp = $this->llm->complete(
                [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => "ITEM: {$text}"]],
                [
                    'model' => (string) config('classify.flow.ensemble.understand_model', 'deepseek/deepseek-v4-flash:online'),
                    'timeout' => (int) config('classify.flow.ensemble.understand_timeout', 120),
                ],
            );
            $j = json_decode((string) preg_replace('/^```json|```$/m', '', trim((string) ($resp['content'] ?? ''))), true);
            if (! is_array($j)) {
                return null;
            }
            $identity = trim((string) ($j['identity'] ?? ''));
            if ($identity === '') {
                return null;
            }
            $syns = [];
            foreach ((array) ($j['synonyms'] ?? []) as $s) {
                $s = trim((string) $s);
                if ($s !== '' && ! in_array($s, $syns, true)) {
                    $syns[] = $s;
                }
            }

            return [
                'identity' => $identity,
                'az_reading' => trim((string) ($j['az_reading'] ?? '')),
                'synonyms' => array_slice($syns, 0, 6),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * One grounded chooser call (search-free): pick a heading from the shortlist for the
     * given grounding. Returns a 4-digit heading / '99' / 'NONE' / 'ERR'.
     */
    private function ensembleChoose(string $grounding, string $text, string $list): string
    {
        $sys = 'You assign ONE Azerbaijani e-invoice line item to a 4-digit XIF MN / HS heading. '
            .'You get a plain-language description of WHAT THE ITEM IS and a SHORTLIST of candidate '
            .'headings. CHOOSE the one heading whose scope best matches — these are the ONLY allowed '
            .'answers. If none truly fits, answer NONE. Respond strict JSON only: '
            .'{"heading":"<one listed code, or NONE>"}';
        $usr = "WHAT THE ITEM IS: {$grounding}\nORIGINAL TEXT: {$text}\n\nSHORTLIST (choose one):\n{$list}";

        try {
            $resp = $this->llm->complete(
                [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $usr]],
                [
                    'model' => (string) config('classify.flow.ensemble.model', 'deepseek/deepseek-v4-flash'),
                    'timeout' => (int) config('classify.flow.ensemble.timeout', 60),
                ],
            );

            // parse() collapses the JSON to a 4-digit heading / '99' / null; null → NONE-or-unreadable.
            $d = $this->parse((string) ($resp['content'] ?? ''));

            return $d !== null && $d['heading'] !== null ? $d['heading'] : 'NONE';
        } catch (Throwable) {
            return 'ERR';
        }
    }

    /**
     * Majority vote over the picks, restricted to headings actually on the shortlist.
     * Returns [answer|null, agreement] with agreement ∈ unanimous|majority|split|weak.
     *
     * @param  array<int, string>  $picks
     * @param  array<int, string>  $shortlist
     * @return array{0: ?string, 1: string}
     */
    private function tally(array $picks, array $shortlist): array
    {
        $valid = array_values(array_filter(
            $picks,
            fn ($p) => $p !== 'NONE' && $p !== 'ERR' && in_array($p, $shortlist, true),
        ));
        if (count($valid) < 2) {
            return [null, 'weak'];
        }

        $counts = array_count_values($valid);
        arsort($counts);
        $top = (string) array_key_first($counts); // (string): PHP casts numeric array keys to int
        $topCount = $counts[$top];
        $distinct = count($counts);

        $agreement = match (true) {
            $distinct === 1 => 'unanimous',
            $topCount >= 2 => 'majority',
            default => 'split',
        };

        return [in_array($agreement, ['unanimous', 'majority'], true) ? $top : null, $agreement];
    }

    /**
     * Commit an agreed ensemble verdict: flip the still-conflicting item to 'ai_resolved'
     * at the voted 4-digit heading. Conditional update so a human decision that landed while
     * queued is never overwritten. Deliberately does NOT promote to answer_cache while the
     * flow is under evaluation.
     *
     * @param  array{answer: ?string, kind: string, agreement: string, picks: array<int, string>, groundings: array<int, string>, shortlist: array<int, string>}  $ens
     */
    private function applyEnsemble(ClassificationItem $item, array $ens): void
    {
        ClassificationItem::whereKey($item->id)
            ->whereIn('resolution', ['conflict', 'review'])
            ->update([
                'resolution' => 'ai_resolved',
                'final_code' => $ens['answer'],
                'final_catalog_id' => null,
                'kind' => $ens['kind'],
            ]);
    }

    /**
     * Record the ensemble verdict as a non-authoritative mechanism='ensemble' trace row
     * (ignored by Consensus), so the decision/review page and TestRun can inspect it.
     *
     * @param  array{answer: ?string, kind: string, agreement: string, picks: array<int, string>, groundings: array<int, string>, shortlist: array<int, string>}  $ens
     */
    private function traceEnsemble(ClassificationItem $item, array $ens): void
    {
        $conf = match ($ens['agreement']) {
            'unanimous' => (float) config('classify.flow.ensemble.confidence_unanimous', 0.9),
            'majority' => (float) config('classify.flow.ensemble.confidence_majority', 0.8),
            default => null,
        };
        $committed = in_array($ens['agreement'], ['unanimous', 'majority'], true) && $ens['answer'] !== null;
        $shadow = (bool) config('classify.flow.shadow');

        $item->results()->updateOrCreate(
            ['mechanism' => 'ensemble'],
            [
                'matched_code' => $ens['answer'],
                'catalog_id' => null,
                'kind' => $ens['kind'],
                'status' => $committed ? ($shadow ? 'shadow' : 'auto_confirmed') : 'needs_review',
                'confidence' => $conf,
                'candidates' => [],
                'explanation' => 'Understood as "'.($ens['understanding']['identity'] ?? '?').'". Ensemble ('
                    .$ens['agreement'].'): '.implode(' / ', $ens['picks'])
                    .($shadow && $committed ? ' [shadow — web answer served]' : ''),
                'model' => (string) config('classify.flow.ensemble.model', 'deepseek/deepseek-v4-flash'),
                'trace' => [
                    'agreement' => $ens['agreement'],
                    'answer' => $ens['answer'],
                    'picks' => $ens['picks'],
                    'shortlist' => $ens['shortlist'],
                    'understanding' => $ens['understanding'] ?? null,
                    'shadow' => $shadow,
                    'committed' => $committed,
                ],
            ],
        );
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
    private function trace(ClassificationItem $item, ?string $code, ?string $kind, ?float $confidence, string $status, string $reason, string $model, ?string $headingName = null): ClassificationResult
    {
        return $item->results()->updateOrCreate(
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
