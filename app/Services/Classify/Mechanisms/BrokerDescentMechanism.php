<?php

namespace App\Services\Classify\Mechanisms;

use App\Models\CatalogCode;
use App\Models\RubricatorNode;
use App\Services\Classify\CatalogRetriever;
use App\Services\Classify\ClassifierService;
use App\Services\Classify\ProductFactLookupService;
use App\Services\Llm\OpenRouterClient;
use App\Support\LlmLog;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Broker-descent: walks the rubricator top-down like a customs broker. At each
 * fork it names the distinguishing criterion and decides by the SAMPLE LEAVES
 * under each child (where the branch LEADS), not the bare title — deciding by
 * function, not a matching word. Descends top-1 while the fork is decisive; on
 * an undecided fork it tries to acquire the one missing fact, and otherwise
 * constrains a retrieval fallback to the deepest confident prefix (never a blind
 * guess). Uses the strong model only.
 */
final class BrokerDescentMechanism implements ClassifierMechanism
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly CatalogRetriever $retriever,
        private readonly ProductFactLookupService $facts,
        private readonly ClassifierService $classifier,
    ) {}

    public function key(): string
    {
        return 'broker';
    }

    public function classify(string $text): MechanismResult
    {
        $text = trim($text);
        $cfg = (array) config('classify.broker');
        $model = (string) ($cfg['model'] ?? 'openai/gpt-4o');
        $usage = $this->zeroUsage();
        $path = [];
        $confidences = [];
        $node = null;
        $lookups = 0;

        if ($text === '') {
            return $this->result(null, $path, $usage, [], 'error', 'Empty item.', [], $model);
        }

        // Reason over the item's ESSENCE, not a bare noisy token — the same
        // canonical description that drives vector retrieval. A context-free word
        // (e.g. "qrelka") otherwise makes the top fork a blind guess.
        $q = $text;

        try {
            $essence = trim($this->classifier->canonicalize($text));
            if ($essence !== '' && mb_strtolower($essence) !== mb_strtolower($text)) {
                $q = $text."\nNormalized: ".$essence;
            }

            $children = RubricatorNode::whereNull('parent_id')->orderBy('code')->get();

            for ($depth = 0; $depth < (int) ($cfg['max_depth'] ?? 5); $depth++) {
                if ($children->isEmpty()) {
                    break; // $node is the deepest rubric — go to leaf mode
                }

                if ($children->count() === 1) {
                    $node = $children->first();
                    $path[] = $this->step($node, 'only-child');
                    $children = $node->children;

                    continue;
                }

                $decision = $this->decide($q, $children, $model, $usage);
                $chosen = $this->pick($children, $decision['choice']);
                $ok = $this->decisive($decision, $chosen, $cfg);

                // One targeted fact-acquisition retry on an undecided fork.
                if (! $ok && $decision['question'] !== null && $lookups < (int) ($cfg['max_lookups'] ?? 1)) {
                    $lookups++;
                    $fact = $this->facts->lookup($text, $decision['question'], (float) ($cfg['fact_min'] ?? 0.7));
                    if ($fact !== null) {
                        $decision = $this->decide($q, $children, $model, $usage, $fact);
                        $chosen = $this->pick($children, $decision['choice']);
                        $ok = $this->decisive($decision, $chosen, $cfg);
                    }
                }

                if (! $ok) {
                    // Undecided: do NOT guess deeper — constrain a retrieval
                    // fallback to the deepest prefix we are still confident about.
                    return $this->fallback($q, $node, $path, $usage, $confidences, $model, 'Undecided fork; constrained fallback.');
                }

                $node = $chosen;
                $confidences[] = $decision['confidence'];
                $path[] = $this->step($node, 'decided', $decision['criterion'], $decision['confidence']);
                $children = $node->children;
            }

            return $this->leafMode($q, $node, $path, $usage, $confidences, $model);
        } catch (Throwable $e) {
            return $this->fallback($q, $node, $path, $usage, $confidences, $model, 'Broker error: '.$e->getMessage());
        }
    }

    /**
     * Choose the final 10-digit code among the leaves under the deepest rubric.
     *
     * @param  array<int, array<string, mixed>>  $path
     * @param  array<string, int>  $usage
     * @param  array<int, float>  $confidences
     */
    private function leafMode(string $text, ?RubricatorNode $node, array $path, array $usage, array $confidences, string $model): MechanismResult
    {
        if ($node === null) {
            return $this->fallback($text, null, $path, $usage, $confidences, $model, 'No rubric reached.');
        }

        $leaves = $this->leavesUnder($node);
        if ($leaves->isEmpty() || $leaves->count() > (int) config('classify.broker.leaf_direct_max', 20)) {
            // Too many (or zero) direct leaves — narrow via retrieval on the prefix.
            return $this->fallback($text, $node, $path, $usage, $confidences, $model, 'Leaf narrowing via retrieval.');
        }

        if ($leaves->count() === 1) {
            $leaf = $leaves->first();
            $path[] = ['code' => $leaf->code, 'by' => 'single-leaf'];

            return $this->finalize($leaf->code, $path, $usage, $confidences, null, $this->candidates($leaves), $model, clean: true, query: $text);
        }

        $pick = $this->leafPick($text, $leaves, $model, $usage);
        $path[] = ['code' => $pick['code'], 'by' => 'leaf-pick', 'confidence' => $pick['confidence']];
        if ($pick['code'] !== null) {
            $confidences[] = $pick['confidence'];
        }

        return $this->finalize($pick['code'], $path, $usage, $confidences, $pick['reason'], $this->candidates($leaves), $model, clean: true, query: $text);
    }

    /**
     * Constrain-and-delegate: retrieve candidates (optionally filtered to the
     * confident prefix) and re-rank them, so a failed/undecided descent still
     * returns the best available code instead of a blind guess.
     *
     * @param  array<int, array<string, mixed>>  $path
     * @param  array<string, int>  $usage
     * @param  array<int, float>  $confidences
     */
    private function fallback(string $text, ?RubricatorNode $node, array $path, array $usage, array $confidences, string $model, ?string $reason): MechanismResult
    {
        $objects = $this->retriever->candidates([$text], (int) config('classify.candidates', 24));

        if ($node !== null) {
            $prefixed = array_values(array_filter($objects, fn ($c) => str_starts_with((string) $c->code, $node->code)));
            if ($prefixed !== []) {
                $objects = $prefixed;
            }
        }

        $path[] = ['by' => 'fallback', 'prefix' => $node?->code];

        if ($objects === []) {
            return $this->result(null, $path, $usage, $confidences, 'no_match', $reason ?? 'No candidates found.', [], $model);
        }

        $leaves = collect($objects)->map(fn ($c) => (object) ['code' => $c->code, 'name' => $c->name, 'kind' => $c->kind]);
        $pick = $this->leafPick($text, $leaves, $model, $usage);

        // Fallback picks never count as a clean descent — they go to review.
        return $this->finalize($pick['code'], $path, $usage, $confidences, $reason ?? $pick['reason'], $this->candidates($leaves), $model, clean: false, query: $text);
    }

    /** @param array<int, array<string, mixed>> $path @param array<string, int> $usage @param array<int, float> $confidences @param array<int, mixed> $candidates */
    private function finalize(?string $code, array $path, array $usage, array $confidences, ?string $reason, array $candidates, string $model, bool $clean, string $query = ''): MechanismResult
    {
        if ($code === null) {
            return $this->result(null, $path, $usage, $confidences, 'no_match', $reason ?? 'No confident match.', $candidates, $model);
        }

        $cat = CatalogCode::where('code', $code)->first();
        if ($cat === null) {
            return $this->result(null, $path, $usage, $confidences, 'no_match', 'Picked code not in catalog.', $candidates, $model);
        }

        // Confidence is the WEAKEST link across the descent + leaf pick.
        $confidence = $confidences !== [] ? round(min($confidences), 3) : null;

        // Auto-confirm needs a clean, confident descent AND semantic backing —
        // the cosine of the item to the chosen leaf clears min_semantic (same gate
        // as vector). A confident-but-context-starved guess ("qrelka" -> a donkey)
        // has weak cosine backing, so it drops to review instead of auto-confirm.
        $backed = false;
        if ($clean && $confidence !== null && $confidence >= (float) config('classify.auto_confirm', 0.8)) {
            $sim = $this->retriever->semanticSimilarity($query, $cat->code);
            $backed = $sim !== null && $sim >= (float) config('classify.min_semantic', 0.5);
        }

        return new MechanismResult(
            matchedCode: $cat->code,
            catalogId: $cat->id,
            kind: $cat->kind, // authoritative (99 => service)
            confidence: $confidence,
            status: $backed ? 'auto_confirmed' : 'needs_review',
            candidates: $candidates,
            path: $path,
            explanation: $reason,
            model: $model,
            tier: null,
            usage: $usage,
        );
    }

    /** One DECIDE-NODE call: name the criterion, judge branches by their leaves, pick a child. */
    private function decide(string $text, Collection $children, string $model, array &$usage, ?string $fact = null): array
    {
        $sample = (int) config('classify.broker.sample_leaves', 12);
        $branches = $children->map(function (RubricatorNode $c) use ($sample) {
            $leaves = $c->sampleLeaves($sample)->pluck('name')
                ->map(fn ($n) => mb_substr((string) $n, 0, 80))->implode('; ');
            $title = $c->title ?: $c->code;

            return "code={$c->code} | {$title}\n    e.g.: {$leaves}";
        })->implode("\n");

        $factLine = $fact !== null ? "\nKNOWN FACT: {$fact}" : '';
        $messages = [
            ['role' => 'system', 'content' => $this->decidePrompt()],
            ['role' => 'user', 'content' => "ITEM: {$text}{$factLine}\n\nBRANCHES:\n{$branches}"],
        ];

        $response = $this->llm->jsonWithUsage($messages, ['model' => $model]);
        LlmLog::record('broker_decide', $response['model'], $response['usage'], $response['latency_ms'] ?? 0,
            'ok', $response['raw'] ?? null, $messages, 'broker', null, ['item' => mb_substr($text, 0, 80)]);
        $this->addUsage($usage, $response['usage']);

        $d = $response['data'];
        $criterion = trim((string) ($d['criterion'] ?? ''));

        return [
            'criterion' => $criterion,
            'choice' => $this->str($d['choice'] ?? null),
            'confidence' => (float) ($d['confidence'] ?? 0),
            // A pick with no stated criterion is treated as a guess -> not decisive.
            'decisive' => (bool) ($d['decisive'] ?? false) && $criterion !== '',
            'question' => $this->str($d['question'] ?? null),
        ];
    }

    /** One LEAF-PICK call: choose the final 10-digit code among sibling leaves. */
    private function leafPick(string $text, Collection $leaves, string $model, array &$usage): array
    {
        $list = $leaves->values()
            ->map(fn ($l, $i) => ($i + 1).". code={$l->code} ".mb_substr((string) $l->name, 0, 150))
            ->implode("\n");

        $messages = [
            ['role' => 'system', 'content' => $this->leafPrompt()],
            ['role' => 'user', 'content' => "ITEM: {$text}\n\nCODES:\n{$list}"],
        ];

        $response = $this->llm->jsonWithUsage($messages, ['model' => $model]);
        LlmLog::record('broker_leaf', $response['model'], $response['usage'], $response['latency_ms'] ?? 0,
            'ok', $response['raw'] ?? null, $messages, 'broker', null, ['item' => mb_substr($text, 0, 80)]);
        $this->addUsage($usage, $response['usage']);

        $d = $response['data'];

        return [
            'code' => $this->str($d['code'] ?? null),
            'confidence' => (float) ($d['confidence'] ?? 0),
            'reason' => $this->str($d['reason'] ?? null),
        ];
    }

    /** @return Collection<int, CatalogCode> */
    private function leavesUnder(RubricatorNode $node): Collection
    {
        $column = match ($node->level) {
            1 => 'chapter',
            2 => 'position',
            default => 'subposition',
        };

        return CatalogCode::query()
            ->where($column, $node->code)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'kind']);
    }

    private function decisive(array $decision, ?RubricatorNode $chosen, array $cfg): bool
    {
        return $chosen !== null
            && $decision['decisive']
            && $decision['confidence'] >= (float) ($cfg['node_min_conf'] ?? 0.6);
    }

    private function pick(Collection $children, ?string $code): ?RubricatorNode
    {
        return $code !== null ? $children->firstWhere('code', $code) : null;
    }

    /** @param Collection<int, object> $leaves @return array<int, array<string, mixed>> */
    private function candidates(Collection $leaves): array
    {
        return $leaves->take(24)->map(fn ($l) => [
            'code' => $l->code,
            'kind' => $l->kind ?? null,
            'name' => $l->name,
        ])->values()->all();
    }

    private function step(RubricatorNode $node, string $by, ?string $criterion = null, ?float $confidence = null): array
    {
        return array_filter([
            'code' => $node->code,
            'title' => $node->title,
            'by' => $by,
            'criterion' => $criterion,
            'confidence' => $confidence,
        ], fn ($v) => $v !== null);
    }

    /** @param array<int, array<string, mixed>> $path @param array<string, int> $usage @param array<int, float> $confidences @param array<int, mixed> $candidates */
    private function result(?string $code, array $path, array $usage, array $confidences, string $status, ?string $reason, array $candidates, string $model): MechanismResult
    {
        return new MechanismResult(
            matchedCode: $code,
            catalogId: null,
            kind: null,
            confidence: $confidences !== [] ? round(min($confidences), 3) : null,
            status: $status,
            candidates: $candidates,
            path: $path,
            explanation: $reason,
            model: $model,
            tier: null,
            usage: $usage,
        );
    }

    private function str(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : $v;

        return ($v === null || $v === '' || $v === 'null') ? null : (string) $v;
    }

    /** @return array<string, int> */
    private function zeroUsage(): array
    {
        return ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    }

    /** @param array<string, int> $usage @param array<string, int> $add */
    private function addUsage(array &$usage, array $add): void
    {
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $k) {
            $usage[$k] += (int) ($add[$k] ?? 0);
        }
    }

    private function decidePrompt(): string
    {
        return <<<'PROMPT'
        You are a customs classification expert navigating Azerbaijan's XİF MN
        (HS) tree top-down. You are at one fork. You receive an ITEM and the child
        BRANCHES, each with its code, title and a few EXAMPLE member items.

        Decide which single branch the item belongs under. Rules:
        - Judge each branch by WHERE IT LEADS — its example items — not its title.
        - Decide by what the item functionally IS (its purpose), not by a shared
          word or the material it is made of.
        - Name the ONE distinguishing criterion that separates these branches.
        - If two branches are genuinely equally plausible AND the item text lacks
          the fact that would decide between them, set "decisive" to false and put
          the single yes/no or short factual question in "question".

        Respond with strict JSON only:
        {"criterion":"<what separates the branches>","choice":"<chosen child code or null>","confidence":0.0,"decisive":true,"question":"<the missing fact question, or empty>","reason":"<short>"}
        PROMPT;
    }

    private function leafPrompt(): string
    {
        return <<<'PROMPT'
        You are a customs classification expert. Pick the SINGLE best matching
        10-digit XİF MN code for the ITEM from the CODES list. Classify by the
        item's function/purpose, not merely its material. Choose only from the
        list; if none is a reasonable match, set "code" to null. Calibrate
        "confidence" (0..1) honestly.

        Respond with strict JSON only:
        {"code":"<chosen code or null>","confidence":0.0,"reason":"<short>"}
        PROMPT;
    }
}
