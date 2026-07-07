<?php

namespace App\Services\Classify;

use App\Models\CatalogCode;
use App\Models\ClassificationAdjudication;
use App\Models\ClassificationItem;
use App\Models\HsCard;
use App\Services\Llm\OpenRouterClient;
use App\Support\LlmLog;
use Throwable;

/**
 * AI ADJUDICATOR for divergent items. When two independent mechanisms disagree
 * (conflict) or agree but without confidence (review), a reasoning model is asked
 * whether ONE code is UNAMBIGUOUSLY correct — choosing ONLY among the codes the
 * mechanisms already surfaced, grounded in the item's understanding + the HS legal
 * cards, and abstaining by default. A stability re-sample must agree before a
 * verdict counts. Its judgment is recorded for audit/measurement; whether it may
 * actually change the resolution is the caller's (job + config) decision.
 *
 * Deliberately a DIFFERENT model family from the DeepSeek mechanisms, so the judge
 * does not simply share and rubber-stamp their correlated blind spots.
 */
class AdjudicatorService
{
    public function __construct(private readonly OpenRouterClient $llm) {}

    /**
     * Judge one divergent item and persist the verdict. Idempotent per
     * (item, prompt_version): a prior verdict is returned as-is (no repeated paid
     * call). Returns null only when the item cannot be judged (not divergent /
     * disabled). Never throws.
     */
    public function run(ClassificationItem $item): ?ClassificationAdjudication
    {
        $cfg = (array) config('classify.adjudicator');
        $version = (string) ($cfg['prompt_version'] ?? 'j1');

        $existing = ClassificationAdjudication::where('classification_item_id', $item->id)
            ->where('prompt_version', $version)->first();
        if ($existing !== null) {
            return $existing;
        }

        $item->loadMissing('results');
        $results = $item->results->keyBy('mechanism');
        $broker = $results['broker'] ?? null;
        $vector = $results['vector'] ?? null;
        if ($broker === null && $vector === null) {
            return null;
        }

        $model = (string) ($cfg['model'] ?? 'openai/gpt-oss-120b');
        $usage = $this->zeroUsage();
        $samples = [];

        try {
            $messages = $this->messagesFor($item, $broker, $vector);
            $n = max(1, (int) ($cfg['samples'] ?? 2));
            $temp = (float) ($cfg['sample_temperature'] ?? 0.5);
            for ($i = 0; $i < $n; $i++) {
                $samples[] = $this->askJudge($messages, $model, $i === 0 ? 0.0 : $temp, $usage);
            }
        } catch (Throwable) {
            // fall through — an empty $samples yields verdict=error below
        }

        $verdict = $this->reconcile($samples, $item->allowedCodes());

        return ClassificationAdjudication::updateOrCreate(
            ['classification_item_id' => $item->id, 'prompt_version' => $version],
            [
                'resolution_before' => (string) $item->resolution,
                'model' => $model,
                'verdict' => $verdict['verdict'],
                'winning_code' => $verdict['winning_code'],
                'winning_kind' => $verdict['winning_kind'],
                'confidence' => $verdict['confidence'],
                'which_mechanism' => $verdict['which'],
                'stable' => $verdict['stable'],
                'had_abstention' => $broker?->matched_code === null || $vector?->matched_code === null,
                'rule_basis' => $verdict['rule_basis'],
                'reason' => $verdict['reason'],
                'mode' => (string) ($cfg['mode'] ?? 'shadow'),
                'applied' => false,
                'holdout' => false,
                'samples' => array_map(fn ($s) => $s === null ? null : ['verdict' => $s['verdict'], 'code' => $s['winning_code']], $samples),
                'usage' => $usage,
            ],
        );
    }

    /**
     * Collapse the samples into one verdict. Only a stable, on-list "resolved"
     * survives: the first sample is the answer, every other sample must resolve to
     * the SAME code, and that code must be one the mechanisms actually proposed.
     *
     * @param  array<int, array<string, mixed>|null>  $samples
     * @param  array<int, string>  $allowed
     * @return array<string, mixed>
     */
    private function reconcile(array $samples, array $allowed): array
    {
        $base = ['verdict' => 'uncertain', 'winning_code' => null, 'winning_kind' => null, 'confidence' => null, 'which' => null, 'rule_basis' => null, 'reason' => null, 'stable' => false];

        $primary = $samples[0] ?? null;
        if ($primary === null) {
            return ['verdict' => 'error'] + $base;
        }

        // The primary sample's metadata rides along on every outcome.
        $meta = [
            'winning_kind' => $primary['winning_kind'],
            'confidence' => $primary['confidence'],
            'which' => $primary['which'],
            'rule_basis' => $primary['rule_basis'],
            'reason' => $this->str(($primary['reason'] ?? '').$this->sourceNote($primary['sources'] ?? [])),
        ];
        $out = fn (array $extra) => array_merge($base, $meta, $extra);

        $primaryCode = $primary['verdict'] === 'resolved' ? ($primary['winning_code'] ?? null) : null;
        if ($primaryCode === null) {
            return $out([]); // uncertain
        }

        $codes = collect($samples)->map(fn ($s) => (string) ($s['winning_code'] ?? ''));
        $allResolved = collect($samples)->every(fn ($s) => $s !== null && $s['verdict'] === 'resolved' && $s['winning_code'] !== null);
        $heading = mb_substr((string) $primaryCode, 0, 4);
        $candidateHeadings = collect($allowed)->map(fn ($c) => mb_substr((string) $c, 0, 4))->unique();

        // FULL: an exact 10-digit catalog code, on the candidate list, agreed by every sample.
        if (mb_strlen((string) $primaryCode) === 10 && in_array($primaryCode, $allowed, true)
            && $allResolved && $codes->every(fn ($c) => $c === (string) $primaryCode)) {
            $cat = CatalogCode::where('code', $primaryCode)->first();
            if ($cat !== null) {
                return $out(['verdict' => 'resolved', 'winning_code' => $cat->code, 'winning_kind' => $cat->kind, 'stable' => true]);
            }
        }

        // HEADING: every sample agrees on a 4-digit heading the mechanisms actually reached
        // — a good-enough answer when the exact subheading can't be pinned. Full is preferred
        // above; this is the honest fallback (a bare 4-digit code as the result).
        if ($allResolved && $candidateHeadings->contains($heading) && $codes->every(fn ($c) => mb_substr($c, 0, 4) === $heading)) {
            return $out(['verdict' => 'resolved', 'winning_code' => $heading, 'stable' => true]);
        }

        // A pick exists but the samples don't agree even at the heading → record it, not
        // stable, so it goes to a human.
        $onList = mb_strlen((string) $primaryCode) === 10 && in_array($primaryCode, $allowed, true);

        return $out([
            'verdict' => $onList ? 'resolved' : 'uncertain',
            'winning_code' => $onList ? $primaryCode : null,
            'stable' => false,
        ]);
    }

    /** One judge call → parsed verdict, or null on any failure. */
    private function askJudge(array $messages, string $model, float $temperature, array &$usage): ?array
    {
        try {
            $resp = $this->llm->complete($messages, ['model' => $model, 'temperature' => $temperature]);
            LlmLog::record('adjudicate', $resp['model'] ?? $model, $resp['usage'] ?? [], 0, 'ok',
                $resp['content'] ?? null, $messages, 'adjudicator');
            $this->addUsage($usage, $resp['usage'] ?? []);

            $parsed = $this->parseVerdict((string) ($resp['content'] ?? ''));
            if ($parsed !== null) {
                $parsed['sources'] = $resp['annotations'] ?? []; // web citations, if it searched
            }

            return $parsed;
        } catch (Throwable) {
            return null;
        }
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
     * Extract the structured verdict from a reasoning model's output — it emits
     * chain-of-thought (which contains stray braces), so parse the LAST JSON object
     * AFTER the ===VERDICT=== sentinel, having stripped any <think> block.
     *
     * @return array<string, mixed>|null
     */
    private function parseVerdict(string $content): ?array
    {
        $content = (string) preg_replace('#<think>.*?</think>#is', '', $content);
        $after = strstr($content, '===VERDICT===');
        $tail = $after !== false ? substr($after, strlen('===VERDICT===')) : $content;

        if (! preg_match_all('/\{.*\}/s', $tail, $m) || $m[0] === []) {
            return null;
        }
        $d = json_decode(end($m[0]), true);
        if (! is_array($d)) {
            return null;
        }

        $verdict = in_array($d['verdict'] ?? null, ['resolved', 'uncertain'], true) ? $d['verdict'] : 'uncertain';

        // The judge may answer with a full 10-digit code OR, when the exact subheading
        // is undetermined, just the 4-digit HS heading. Keep digits only; a 5–9 digit
        // partial collapses to its 4-digit heading.
        $digits = preg_replace('/\D/', '', (string) ($d['winning_code'] ?? ''));
        $code = mb_strlen($digits) >= 10 ? mb_substr($digits, 0, 10) : (mb_strlen($digits) >= 4 ? mb_substr($digits, 0, 4) : null);

        return [
            'verdict' => $verdict,
            'winning_code' => $code,
            'winning_kind' => in_array($d['winning_kind'] ?? null, ['good', 'service'], true) ? $d['winning_kind'] : null,
            'confidence' => round((float) ($d['confidence'] ?? 0), 3),
            'which' => in_array($d['which'] ?? null, ['broker', 'vector', 'both', 'neither'], true) ? $d['which'] : null,
            'rule_basis' => $this->str($d['rule_basis'] ?? null),
            'reason' => $this->str($d['reason'] ?? null),
        ];
    }

    /**
     * Build the judge prompt: the item's understanding, each mechanism's pick (or
     * abstention), the candidate universe with official names, and the HS legal
     * cards for the competing headings.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function messagesFor(ClassificationItem $item, ?object $broker, ?object $vector): array
    {
        $brief = $broker?->trace['brief'] ?? null;
        $identity = $brief['identity'] ?? ($broker?->trace['essence'] ?? (string) $item->source_text);
        $purpose = $brief['purpose'] ?? '';

        // Feed the judge the ENGLISH names (fallback to the Azerbaijani) — it reads
        // English far better than AZ, and the brief's identity is already English.
        $candLines = CatalogCode::whereIn('code', $item->allowedCodes())->get(['code', 'name', 'name_en'])
            ->map(fn ($c) => '  '.$c->code.' | '.mb_substr((string) ($c->name_en ?: $c->name), 0, 130))
            ->implode("\n");

        // Cards for the 4-digit headings of the two picks — the legal rulebook.
        $headings = collect([$broker?->matched_code, $vector?->matched_code])
            ->filter()->map(fn ($c) => mb_substr((string) $c, 0, 4))->unique()->values();
        $cards = HsCard::whereIn('code', $headings)->where('is_active', true)->get()
            ->map(fn ($c) => $c->promptBlock(''))->filter()->implode("\n");

        $pick = fn (?object $r) => $r?->matched_code
            ?: 'ABSTAINED ('.mb_substr((string) ($r?->explanation ?? 'no code'), 0, 90).')';

        $user = "ITEM (understanding): {$identity}".($purpose !== '' ? ". Purpose: {$purpose}" : '')."\n"
            ."RAW (Azerbaijani): {$item->source_text}\n"
            .'BROKER picked: '.$pick($broker)."\n"
            .'VECTOR picked: '.$pick($vector)."\n\n"
            ."CANDIDATES (code | official name):\n{$candLines}\n"
            .($cards !== '' ? "\nHS CARDS (binding legal rules for the competing headings):\n{$cards}\n" : '');

        return [
            ['role' => 'system', 'content' => $this->prompt()],
            ['role' => 'user', 'content' => $user],
        ];
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
        You are a senior customs-classification ARBITER for the Azerbaijani XİF MN
        (HS) nomenclature. Two independent classifiers disagreed, or agreed but
        without confidence, on ONE item. Decide the correct classification: ideally ONE
        10-digit code from the candidates; but when the exact subheading cannot be
        pinned and the 4-digit HS heading is nonetheless clear, resolve at that HEADING
        (a bare 4-digit code) rather than giving up.

        You may USE WEB SEARCH: if the item is an unfamiliar brand, drug, or product,
        look up what it actually is (its category, active ingredient, material) before
        deciding which candidate fits. Identify first, then rule.

        FIRST settle GOOD vs SERVICE. The candidates may mix physical goods with service
        codes (chapter 99). A line is either the supply of a THING or the performance of
        WORK on/with a thing — repair, installation, maintenance, transport. If a physical
        object is named but the line's point is an ACTION on it (a trailing "(təmiri)" /
        "quraşdırılması" / "ремонт"), it IS that service — pick the service candidate; the
        object only says what the work is on. A part supplied on its own, no action, is a
        GOOD. Decide this axis before comparing codes.

        Hard rules:
        - PREFER a full 10-digit code when ONE candidate is clearly right.
        - HEADING FALLBACK — use this instead of abstaining whenever the heading is clear.
          If you know the correct 4-digit HS heading and AT LEAST ONE candidate is in that
          heading, but no candidate's exact subheading is clearly right, then RESOLVE AT
          THE HEADING: set winning_code to just those 4 digits. The subheading you would
          prefer does NOT need to be in the list — only the 4-digit heading must match a
          candidate's heading. A candidate "is in heading NNNN" when its code starts with
          NNNN, regardless of its last six digits or its printed name.
          WORKED EXAMPLE: candidates are 2202901000 and 2203000100; you determine the item
          is non-alcoholic beer, which is heading 2202; the exact 2202.29 subheading is not
          listed — but candidate 2202901000 IS in heading 2202, so return winning_code
          "2202". Do NOT answer "uncertain" and do NOT say the heading is absent here.
        - NEVER invent a heading that no candidate reaches.
        - Answer verdict="uncertain" ONLY when even the 4-digit heading is genuinely
          unclear, OR two DIFFERENT headings are equally defensible. Not knowing the exact
          subheading is NOT a reason to be uncertain — give the heading.
        - Decide by ESSENTIAL CHARACTER / FUNCTION and the binding HS CARD rules
          (COVERS / INCLUDES / EXCLUDES / CLOSED LIST), NOT by word overlap with a
          code's name. Quote the deciding clause in "rule_basis".
        - If a mechanism ABSTAINED, that is a signal of difficulty, not a free win for
          the other — hold the surviving code to the same bar.

        winning_code is a full 10-digit code, OR a 4-digit heading, OR null; confidence is
        your certainty in the code you actually give (full or heading).
        Reason briefly (a few lines). Then output EXACTLY one line:
        ===VERDICT===
        followed by ONE JSON object and NOTHING after it:
        {"verdict":"resolved|uncertain","winning_code":"<10 digits | 4-digit heading | null>","winning_kind":"good|service|null","confidence":0.0,"which":"broker|vector|both|neither","rule_basis":"the deciding card clause / GIR rule","reason":"short"}
        PROMPT;
    }

    private function str(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : $v;

        return ($v === null || $v === '' || $v === 'null') ? null : (string) $v;
    }

    /** @return array<string, int> */
    private function zeroUsage(): array
    {
        return ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'cached_tokens' => 0];
    }

    /** @param array<string, int> $usage @param array<string, int> $add */
    private function addUsage(array &$usage, array $add): void
    {
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens', 'cached_tokens'] as $k) {
            $usage[$k] += (int) ($add[$k] ?? 0);
        }
    }
}
