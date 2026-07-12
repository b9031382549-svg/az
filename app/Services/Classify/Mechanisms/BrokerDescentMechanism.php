<?php

namespace App\Services\Classify\Mechanisms;

use App\Models\CatalogCode;
use App\Models\HsCard;
use App\Models\RubricatorNode;
use App\Services\Classify\CatalogRetriever;
use App\Services\Classify\ClassifierService;
use App\Services\Classify\ProductBriefService;
use App\Services\Classify\ProductFactLookupService;
use App\Services\Llm\OpenRouterClient;
use App\Support\BreadcrumbName;
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
 *
 * Records a structured `trace` (input -> essence -> forks with alternatives ->
 * leaf -> gate) for the "Decision" screen.
 */
final class BrokerDescentMechanism implements ClassifierMechanism
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly CatalogRetriever $retriever,
        private readonly ProductFactLookupService $facts,
        private readonly ClassifierService $classifier,
        private readonly ProductBriefService $briefs,
    ) {}

    // The upfront brief for the item being classified (null when disabled/failed);
    // read by the review gate in finalize(). Set per-call in classify().
    private ?array $brief = null;

    // The query used ONLY for the semantic auto-confirm backing (cosine). Kept
    // separate from the reasoning query $q so the broker's own brief never inflates
    // its own confirmation — the backing must be an independent view of the item.
    private string $backingQuery = '';

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
        $trace = ['input' => $text, 'essence' => null, 'steps' => [], 'gate' => null];

        if ($text === '') {
            return $this->result(null, $path, $usage, [], 'error', 'Empty item.', [], $model, $trace);
        }

        // Reset per-call state (the mechanism may be resolved once and reused).
        $this->brief = null;
        $this->backingQuery = $text;

        $q = $text;

        try {
            // Clean, brief-INDEPENDENT canonical essence: it is the reasoning substrate
            // when there is no brief, AND always the auto-confirm cosine backing — the
            // same normalized query the vector mechanism embeds — so min_semantic stays
            // in its calibrated regime (0.5 was tuned against clean-query cosine, not raw
            // noisy invoice text). canonicalize() is a separate call from the brief, so
            // using it for backing never lets the brief confirm its own pick.
            $essence = trim($this->classifier->canonicalize($text));
            if ($essence !== '' && mb_strtolower($essence) !== mb_strtolower($text)) {
                $q = $text."\nNormalized: ".$essence;
                $this->backingQuery = $q;
                $trace['essence'] = $essence;
            }

            // The product brief (what the item IS / is for / is made of) REPLACES the
            // essence as the descent's REASONING query — brand/identity kept intact,
            // nothing mangled — while the cosine backing stays on the essence above. Its
            // decisive_axis + material.basis drive the review gate. Absent/failed brief →
            // the essence path above (unchanged pre-brief behaviour); an undecided root
            // still abstains — see fallback() — rather than fabricate a code.
            $brief = $this->briefs->brief($text);
            if ($brief !== null) {
                $this->brief = $brief;
                $q = $this->briefQuery($text, $brief);
                $trace['brief'] = $brief;
            }

            $children = RubricatorNode::whereNull('parent_id')->orderBy('code')->get();

            for ($depth = 0; $depth < (int) ($cfg['max_depth'] ?? 5); $depth++) {
                if ($children->isEmpty()) {
                    break; // $node is the deepest rubric — go to leaf mode
                }

                if ($children->count() === 1) {
                    $node = $children->first();
                    $path[] = $this->step($node, 'only-child');
                    $trace['steps'][] = ['type' => 'auto', 'code' => $node->code, 'title' => $node->title];
                    $children = $node->children;

                    continue;
                }

                $decision = $this->decide($q, $children, $model, $usage);
                $chosen = $this->pick($children, $decision['choice']);
                $ok = $this->decisive($decision, $chosen, $cfg);
                $trace['steps'][] = $this->forkStep($decision, $ok);

                // One targeted fact-acquisition retry on an undecided fork.
                if (! $ok && $decision['question'] !== null && $lookups < (int) ($cfg['max_lookups'] ?? 1)) {
                    $lookups++;
                    $fact = $this->facts->lookup($text, $decision['question'], (float) ($cfg['fact_min'] ?? 0.7));
                    $trace['steps'][] = ['type' => 'fact', 'question' => $decision['question'], 'fact' => $fact];
                    if ($fact !== null) {
                        $decision = $this->decide($q, $children, $model, $usage, $fact);
                        $chosen = $this->pick($children, $decision['choice']);
                        $ok = $this->decisive($decision, $chosen, $cfg);
                        $trace['steps'][] = $this->forkStep($decision, $ok, afterFact: true);
                    }
                }

                if (! $ok) {
                    // Undecided: do NOT guess deeper — constrain a retrieval
                    // fallback to the deepest prefix we are still confident about.
                    return $this->fallback($q, $node, $path, $usage, $confidences, $model, 'Undecided fork; constrained fallback.', $trace);
                }

                $node = $chosen;
                $confidences[] = $decision['confidence'];
                $path[] = $this->step($node, 'decided', $decision['criterion'], $decision['confidence']);
                $children = $node->children;
            }

            return $this->leafMode($q, $node, $path, $usage, $confidences, $model, $trace);
        } catch (Throwable $e) {
            return $this->fallback($q, $node, $path, $usage, $confidences, $model, 'Broker error: '.$e->getMessage(), $trace);
        }
    }

    /**
     * Choose the final 10-digit code among the leaves under the deepest rubric.
     *
     * @param  array<int, array<string, mixed>>  $path
     * @param  array<string, int>  $usage
     * @param  array<int, float>  $confidences
     * @param  array<string, mixed>  $trace
     */
    private function leafMode(string $text, ?RubricatorNode $node, array $path, array $usage, array $confidences, string $model, array $trace): MechanismResult
    {
        if ($node === null) {
            return $this->fallback($text, null, $path, $usage, $confidences, $model, 'No rubric reached.', $trace);
        }

        // 4-digit mode: the descent already fixed the heading — stop here instead of
        // chasing a full leaf code. The leaf only refines digits 5-10 (discarded by the
        // 4-digit consensus) and can abstain when the leaf/fallback fails; stopping keeps
        // those as correct heading votes.
        if ((string) config('classify.broker.answer_granularity', 'code') === 'heading'
            && mb_strlen((string) $node->code) >= 4) {
            return $this->headingResult(mb_substr((string) $node->code, 0, 4), $path, $usage, $confidences, $model, $trace);
        }

        $leaves = $this->leavesUnder($node);
        if ($leaves->isEmpty() || $leaves->count() > (int) config('classify.broker.leaf_direct_max', 20)) {
            // Too many (or zero) direct leaves — narrow via retrieval on the prefix.
            return $this->fallback($text, $node, $path, $usage, $confidences, $model, 'Leaf narrowing via retrieval.', $trace);
        }

        if ($leaves->count() === 1) {
            $leaf = $leaves->first();
            $path[] = ['code' => $leaf->code, 'by' => 'single-leaf'];
            $trace['steps'][] = ['type' => 'leaf', 'options' => $this->leafOptions($leaves), 'chosen' => $leaf->code, 'note' => 'single leaf'];

            return $this->finalize($leaf->code, $path, $usage, $confidences, null, $this->candidates($leaves), $model, true, $trace);
        }

        $pick = $this->leafPick($text, $leaves, $model, $usage);
        $path[] = ['code' => $pick['code'], 'by' => 'leaf-pick', 'confidence' => $pick['confidence']];
        $trace['steps'][] = ['type' => 'leaf', 'options' => $this->leafOptions($leaves), 'chosen' => $pick['code'], 'confidence' => $pick['confidence'], 'reason' => $pick['reason']];
        if ($pick['code'] !== null) {
            $confidences[] = $pick['confidence'];
        }

        return $this->finalize($pick['code'], $path, $usage, $confidences, $pick['reason'], $this->candidates($leaves), $model, true, $trace);
    }

    /**
     * Constrain-and-delegate: retrieve candidates (optionally filtered to the
     * confident prefix) and re-rank them, so a failed/undecided descent still
     * returns the best available code instead of a blind guess.
     *
     * @param  array<int, array<string, mixed>>  $path
     * @param  array<string, int>  $usage
     * @param  array<int, float>  $confidences
     * @param  array<string, mixed>  $trace
     */
    private function fallback(string $text, ?RubricatorNode $node, array $path, array $usage, array $confidences, string $model, ?string $reason, array $trace): MechanismResult
    {
        // No chapter was ever established (undecided at the root, or an error there):
        // the broker genuinely could not classify. ABSTAIN honestly instead of
        // leaf-picking from an unconstrained retrieval on the raw text — that would
        // fabricate an unrelated code that only looks like a broker decision. When a
        // prefix WAS reached (descended part-way), the constrained fallback below is
        // still the broker's own narrowed answer, so we keep it.
        if ($node === null) {
            $path[] = ['by' => 'abstain'];
            $trace['steps'][] = ['type' => 'fallback', 'prefix' => null, 'reason' => $reason, 'options' => [], 'chosen' => null, 'abstained' => true];

            return $this->result(null, $path, $usage, $confidences, 'no_match', $reason ?? 'Broker could not determine a chapter.', [], $model, $trace);
        }

        $objects = $this->retriever->candidates([$text], (int) config('classify.candidates', 24));

        if ($node !== null) {
            $prefixed = array_values(array_filter($objects, fn ($c) => str_starts_with((string) $c->code, $node->code)));
            if ($prefixed !== []) {
                $objects = $prefixed;
            }
        }

        $path[] = ['by' => 'fallback', 'prefix' => $node?->code];

        if ($objects === []) {
            $trace['steps'][] = ['type' => 'fallback', 'prefix' => $node?->code, 'reason' => $reason, 'options' => [], 'chosen' => null];

            return $this->result(null, $path, $usage, $confidences, 'no_match', $reason ?? 'No candidates found.', [], $model, $trace);
        }

        $leaves = collect($objects)->map(fn ($c) => (object) ['code' => $c->code, 'name' => $c->name, 'kind' => $c->kind]);
        $pick = $this->leafPick($text, $leaves, $model, $usage);
        $trace['steps'][] = ['type' => 'fallback', 'prefix' => $node?->code, 'reason' => $reason, 'options' => $this->leafOptions($leaves), 'chosen' => $pick['code'], 'confidence' => $pick['confidence']];

        // Fallback picks never count as a clean descent — they go to review.
        return $this->finalize($pick['code'], $path, $usage, $confidences, $reason ?? $pick['reason'], $this->candidates($leaves), $model, false, $trace);
    }

    /**
     * The reasoning query for the descent: the raw item plus the brief's clean
     * understanding (what it IS / is for / is made of). This REPLACES the noisy
     * canonical essence — brand/identity slots stay intact, so nothing is mangled.
     *
     * @param  array<string, mixed>  $brief
     */
    private function briefQuery(string $text, array $brief): string
    {
        $lines = [$text];
        if (($brief['identity'] ?? '') !== '') {
            $lines[] = 'Understanding: '.$brief['identity'];
        }
        if (($brief['purpose'] ?? '') !== '') {
            $lines[] = 'Purpose: '.$brief['purpose'];
        }
        $material = $brief['material']['value'] ?? null;
        if ($material !== null && $material !== '') {
            $lines[] = 'Made of: '.$material.' ('.($brief['material']['basis'] ?? 'unknown').')';
        }
        if (($brief['function_class'] ?? '') !== '') {
            $lines[] = 'Type: '.$brief['function_class'];
        }

        return implode("\n", $lines);
    }

    /**
     * The assumption gate: a confident, clean pick still needs a human when the
     * classification turns on a material the text never stated. The brief reports
     * both facts — decisive_axis 'material' means the section depends on the
     * material, and basis ≠ 'stated' means the deciding material was assumed, not
     * written. Only that combination forces review.
     */
    private function reviewForcedByBrief(): bool
    {
        if ($this->brief === null) {
            return false;
        }

        return ($this->brief['decisive_axis'] ?? null) === 'material'
            && ($this->brief['material']['basis'] ?? 'unknown') !== 'stated';
    }

    /**
     * 4-digit heading answer (broker.answer_granularity=heading): stop at the deepest
     * confident rubric heading instead of descending to a full leaf code. Kind comes
     * from the catalog for that heading (99 => service); the semantic-backing
     * auto-confirm gate needs a specific code, so a heading vote stays needs_review.
     *
     * @param  array<int, array<string, mixed>>  $path
     * @param  array<string, int>  $usage
     * @param  array<int, float>  $confidences
     * @param  array<string, mixed>  $trace
     */
    private function headingResult(string $heading, array $path, array $usage, array $confidences, string $model, array $trace): MechanismResult
    {
        $kind = CatalogCode::where('position', $heading)->where('is_active', true)->value('kind');
        $confidence = $confidences !== [] ? round(min($confidences), 3) : null;
        $path[] = ['code' => $heading, 'by' => 'heading-stop'];
        $trace['steps'][] = ['type' => 'heading', 'chosen' => $heading, 'note' => 'stopped at 4-digit heading'];
        $trace['gate'] = ['confidence' => $confidence, 'granularity' => 'heading', 'status' => 'needs_review'];

        return new MechanismResult(
            matchedCode: $heading,
            catalogId: null,
            kind: $kind,
            confidence: $confidence,
            status: 'needs_review',
            path: $path,
            explanation: 'Broker stopped at 4-digit heading '.$heading.'.',
            model: $model,
            usage: $usage,
            trace: $trace,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $path
     * @param  array<string, int>  $usage
     * @param  array<int, float>  $confidences
     * @param  array<int, mixed>  $candidates
     * @param  array<string, mixed>  $trace
     */
    private function finalize(?string $code, array $path, array $usage, array $confidences, ?string $reason, array $candidates, string $model, bool $clean, array $trace): MechanismResult
    {
        if ($code === null) {
            return $this->result(null, $path, $usage, $confidences, 'no_match', $reason ?? 'No confident match.', $candidates, $model, $trace);
        }

        $cat = CatalogCode::where('code', $code)->first();
        if ($cat === null) {
            return $this->result(null, $path, $usage, $confidences, 'no_match', 'Picked code not in catalog.', $candidates, $model, $trace);
        }

        // Confidence is the WEAKEST link across the descent + leaf pick.
        $confidence = $confidences !== [] ? round(min($confidences), 3) : null;
        $autoConfirm = (float) config('classify.auto_confirm', 0.8);
        $minSemantic = (float) config('classify.min_semantic', 0.5);

        // Auto-confirm needs a clean, confident descent AND semantic backing —
        // the cosine of the item to the chosen leaf clears min_semantic (same gate
        // as vector). A confident-but-context-starved guess ("qrelka" -> a donkey)
        // has weak cosine backing, so it drops to review instead of auto-confirm.
        $sim = null;
        $backed = false;
        if ($clean && $confidence !== null && $confidence >= $autoConfirm) {
            $sim = $this->retriever->semanticSimilarity($this->backingQuery, $cat->code);
            $backed = $sim !== null && $sim >= $minSemantic;
        }
        $status = $backed ? 'auto_confirmed' : 'needs_review';

        // Assumption gate: when the classification hinges on a material the item text
        // did NOT state (the brief marked decisive_axis=material with basis≠stated), an
        // auto-confirm would be blind — the same article in another material sits in
        // another chapter (a rubber grelka → ch40 vs an electric one → ch85). Send it
        // to a human instead of auto-confirming a guessed material.
        $reviewForced = $status === 'auto_confirmed' && $this->reviewForcedByBrief();
        if ($reviewForced) {
            $status = 'needs_review';
        }

        $trace['gate'] = [
            'confidence' => $confidence,
            'auto_confirm' => $autoConfirm,
            'semantic_sim' => $sim,
            'min_semantic' => $minSemantic,
            'clean' => $clean,
            'review_forced' => $reviewForced ? 'decisive material not stated' : null,
            'status' => $status,
        ];

        return new MechanismResult(
            matchedCode: $cat->code,
            catalogId: $cat->id,
            kind: $cat->kind, // authoritative (99 => service)
            confidence: $confidence,
            status: $status,
            candidates: $candidates,
            path: $path,
            explanation: $reason,
            model: $model,
            tier: null,
            usage: $usage,
            trace: $trace,
        );
    }

    /**
     * The distilled legal cards for this fork's branches, keyed by code. Empty
     * unless enabled (config) and cards exist — so the broker only uses the
     * rulebook where we have authored it, and is unchanged elsewhere.
     *
     * @param  Collection<int, RubricatorNode>  $children
     * @return array<string, HsCard>
     */
    private function cardsFor(Collection $children): array
    {
        if (! config('classify.broker.use_cards', false)) {
            return [];
        }

        return HsCard::whereIn('code', $children->pluck('code'))
            ->where('is_active', true)
            ->get()
            ->keyBy('code')
            ->all();
    }

    /** One DECIDE-NODE call: name the criterion, judge branches by their leaves, pick a child. */
    private function decide(string $text, Collection $children, string $model, array &$usage, ?string $fact = null): array
    {
        // A WIDE fork (the 97-chapter root) with a full card + full samples per
        // branch blows past the model's context (~144k tokens). So at wide forks
        // send a COMPACT card (scope + the reroute EXCLUDES that decide boundaries,
        // e.g. ch30 excludes instruments → 90) and fewer, shorter samples. Narrow
        // forks (positions/subpositions, where closed lists and fine boundaries
        // live) keep the full card + full samples.
        $wide = $children->count() > (int) config('classify.broker.wide_fork', 20);
        $sample = $wide
            ? (int) config('classify.broker.wide_sample_leaves', 4)
            : (int) config('classify.broker.sample_leaves', 12);
        $cards = $this->cardsFor($children);
        $options = [];
        $branchLines = [];
        foreach ($children as $c) {
            // Sample leaves CHARACTERIZE a branch (deciding by function, not the
            // bare title), drawn as an even cross-section of the branch. Shortened
            // at wide forks to keep the prompt within the context window.
            $samples = $c->sampleLeaves($sample)->pluck('name')
                ->map(fn ($n) => $wide ? BreadcrumbName::fit((string) $n, 120) : (string) $n)
                ->implode('; ');
            $title = $c->title ?: $c->code;

            // If a distilled legal card exists for this branch, lead with its rules
            // so the fork is decided by the rulebook, not by the sample leaves
            // alone (compact = scope + excludes at wide forks; full otherwise).
            $cardText = isset($cards[$c->code]) ? $cards[$c->code]->promptBlock('    ', $wide) : '';
            $cardBlock = $cardText !== '' ? "\n".$cardText : '';

            $branchLines[] = "code={$c->code} | {$title}{$cardBlock}\n    e.g.: {$samples}";
            $options[] = ['code' => $c->code, 'title' => $title, 'samples' => mb_substr($samples, 0, 300)];
        }

        $factLine = $fact !== null ? "\nKNOWN FACT: {$fact}" : '';
        // The BRANCHES block is IDENTICAL for every item at a given fork (deterministic
        // sample leaves + static cards), so it goes FIRST — a stable prompt PREFIX the
        // provider can cache (DeepSeek/OpenAI prefix caching). At the 97-chapter root
        // this ~20k-token block is shared across all items, so each item's root fork
        // reuses the cache. The varying ITEM comes LAST, after that cache boundary.
        $messages = [
            ['role' => 'system', 'content' => $this->decidePrompt()],
            ['role' => 'user', 'content' => "BRANCHES:\n".implode("\n", $branchLines)."\n\nITEM: {$text}{$factLine}"],
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
            'options' => $options,
        ];
    }

    /** One LEAF-PICK call: choose the final 10-digit code among sibling leaves. */
    private function leafPick(string $text, Collection $leaves, string $model, array &$usage): array
    {
        // Full names, no truncation: this is the final code choice among a small
        // set of sibling leaves, and the distinguishing detail is in the tail.
        $list = $leaves->values()
            ->map(fn ($l, $i) => ($i + 1).". code={$l->code} ".(string) $l->name)
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
        if ($code === null || $code === '') {
            return null;
        }

        $exact = $children->firstWhere('code', $code);
        if ($exact !== null) {
            return $exact;
        }

        // The model, made confident by the cards, sometimes answers with a MORE
        // specific code than the fork level — e.g. "9018" (a heading) at the
        // 97-chapter root instead of "90". Map it to the child whose code is a
        // prefix, so a correct-but-too-precise answer is accepted (and the descent
        // continues normally) rather than discarded as undecided.
        return $children->first(fn ($c) => str_starts_with($code, (string) $c->code));
    }

    /** A trace record for one fork: the alternatives it weighed and what it chose. */
    private function forkStep(array $decision, bool $accepted, bool $afterFact = false): array
    {
        return [
            'type' => 'fork',
            'options' => $decision['options'],
            'criterion' => $decision['criterion'],
            'chosen' => $decision['choice'],
            'confidence' => $decision['confidence'],
            'decisive' => $decision['decisive'],
            'question' => $decision['question'],
            'accepted' => $accepted,
            'after_fact' => $afterFact,
        ];
    }

    /** @param Collection<int, object> $leaves @return array<int, array<string, string>> */
    private function leafOptions(Collection $leaves): array
    {
        return $leaves->take(30)->map(fn ($l) => [
            'code' => (string) $l->code,
            'name' => BreadcrumbName::fit((string) $l->name),
        ])->values()->all();
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

    /**
     * @param  array<int, array<string, mixed>>  $path
     * @param  array<string, int>  $usage
     * @param  array<int, float>  $confidences
     * @param  array<int, mixed>  $candidates
     * @param  array<string, mixed>  $trace
     */
    private function result(?string $code, array $path, array $usage, array $confidences, string $status, ?string $reason, array $candidates, string $model, array $trace): MechanismResult
    {
        $trace['gate'] ??= ['status' => $status, 'confidence' => $confidences !== [] ? round(min($confidences), 3) : null];

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
            trace: $trace,
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
        (HS) tree top-down. You are at one fork. You receive the child BRANCHES —
        each with its code, title and a few EXAMPLE member items — and then, at the
        end, the ITEM to place under exactly one of them.

        Decide which single branch the item belongs under. Rules:
        - Some branches carry authoritative LEGAL RULES (COVERS / INCLUDES /
          EXCLUDES / CLOSED LIST), distilled from the HS notes. These are BINDING
          and OUTRANK the example items:
            * If the item matches a branch's INCLUDES, choose that branch.
            * If a branch EXCLUDES the item's kind (shown as "→ see heading X"),
              do NOT choose it; choose the referenced branch when it is present.
            * CLOSED LIST means ONLY the listed goods belong there — if the item
              is not plainly one of them, do NOT choose that branch.
            * When a rule decides it, cite that rule in "criterion".
        - Otherwise judge each branch by WHERE IT LEADS — its example items — not
          its title, and by what the item functionally IS (its purpose).
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
