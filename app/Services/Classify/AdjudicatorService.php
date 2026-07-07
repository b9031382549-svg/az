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

        $code = $primary['winning_code'];
        $resolved = $primary['verdict'] === 'resolved' && $code !== null && in_array($code, $allowed, true);

        // Stability: every additional sample must also resolve to the same code.
        $stable = true;
        foreach (array_slice($samples, 1) as $s) {
            if ($s === null || $s['verdict'] !== 'resolved' || $s['winning_code'] !== $code) {
                $stable = false;
                break;
            }
        }

        $cat = $resolved ? CatalogCode::where('code', $code)->first() : null;

        return [
            'verdict' => $resolved && $cat !== null ? 'resolved' : 'uncertain',
            'winning_code' => $cat?->code,
            'winning_kind' => $cat?->kind ?? $primary['winning_kind'],
            'confidence' => $primary['confidence'],
            'which' => $primary['which'],
            'rule_basis' => $primary['rule_basis'],
            'reason' => $this->str(($primary['reason'] ?? '').$this->sourceNote($primary['sources'] ?? [])),
            'stable' => $stable,
        ];
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
        $code = trim((string) ($d['winning_code'] ?? ''));

        return [
            'verdict' => $verdict,
            'winning_code' => $code !== '' && $code !== 'null' ? $code : null,
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
        without confidence, on ONE item. Decide whether EXACTLY ONE 10-digit code is
        UNAMBIGUOUSLY correct.

        You may USE WEB SEARCH: if the item is an unfamiliar brand, drug, or product,
        look up what it actually is (its category, active ingredient, material) before
        deciding which candidate fits. Identify first, then rule.

        Hard rules:
        - Choose ONLY from the CANDIDATES listed. NEVER invent a code.
        - Answer verdict="uncertain" if the correct code is not among the candidates,
          OR two candidates are both defensible, OR a deciding fact (material / exact
          identity / function) is not established in the understanding. Abstaining is
          correct and expected — never force a pick to be helpful.
        - Decide by ESSENTIAL CHARACTER / FUNCTION and the binding HS CARD rules
          (COVERS / INCLUDES / EXCLUDES / CLOSED LIST), NOT by word overlap with a
          code's name. Quote the deciding clause in "rule_basis".
        - If a mechanism ABSTAINED, that is a signal of difficulty, not a free win for
          the other — hold the surviving code to the same bar.

        Reason briefly (a few lines). Then output EXACTLY one line:
        ===VERDICT===
        followed by ONE JSON object and NOTHING after it:
        {"verdict":"resolved|uncertain","winning_code":"<10-digit code or null>","winning_kind":"good|service|null","confidence":0.0,"which":"broker|vector|both|neither","rule_basis":"the deciding card clause / GIR rule","reason":"short"}
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
