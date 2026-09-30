<?php

namespace App\Services\Classify;

use App\Models\ActivityLog;
use App\Models\ClassificationItem;
use App\Models\ClassificationResult;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HOW an item's final answer was reached (method) and WHY that category (reason) — one
 * line each, in the current locale, for the Excel export and the results API. Built only
 * from what the flow already stored (the item, its result rows, the audit trail), along
 * the decision page's stages: memory → trash filter → Direct + vector → ensemble vote →
 * web search → human.
 *
 * Model text is quoted as-is (English — every prompt is) and only where it IS an
 * explanation: the web search's and the ensemble's understanding. Direct's own "reason"
 * is never quoted — the fine-tuned model learned to write its training set's labelling
 * note there ("q122 + blind GPT confirm …"), so its line states the evidence instead:
 * which two independent methods agreed.
 */
final class DecisionSummary
{
    /** Stable method keys — the API returns these; label() localizes them. */
    public const METHODS = ['memory', 'trash', 'consensus', 'ensemble', 'web_search', 'ai', 'human', 'in_progress', 'needs_human'];

    /** A vector row carries ~20 KB of candidates + trace — read the rows a chunk at a time. */
    private const CHUNK = 500;

    private const HUMAN_ACTIONS = ['classification.confirm', 'classification.corrected', 'classification.reject'];

    public static function label(string $method): string
    {
        return match ($method) {
            'memory' => __('Memory'),
            'trash' => __('Trash filter'),
            'consensus' => __('AI: Direct + vector'),
            'ensemble' => __('AI: ensemble vote'),
            'web_search' => __('AI: web search'),
            'ai' => __('AI'),
            'human' => __('Human'),
            'in_progress' => __('In progress'),
            default => __('Not determined'),
        };
    }

    /**
     * Method + reason per item, keyed by item id. Reads the result rows itself (a caller's
     * eager load may be trimmed to a few columns), plus who made each human decision.
     *
     * @param  Collection<int, ClassificationItem>  $items
     * @return array<int, array{method: string, reason: string}>
     */
    public function forItems(Collection $items): array
    {
        $humans = $this->humanDecisions($items);
        $out = [];

        foreach ($items->chunk(self::CHUNK) as $chunk) {
            $results = ClassificationResult::query()
                ->whereIn('classification_item_id', $chunk->pluck('id'))
                ->get([
                    'classification_item_id', 'mechanism', 'matched_code', 'kind', 'confidence',
                    'status', 'explanation', 'candidates',
                    // The vector's trace repeats its candidates (~10 KB a row) and is never read here.
                    DB::raw("CASE WHEN mechanism = 'vector' THEN NULL ELSE trace END AS trace"),
                ])
                ->groupBy('classification_item_id');

            foreach ($chunk as $item) {
                $out[$item->id] = $this->describe($item, $results->get($item->id, collect()), $humans[$item->id] ?? null);
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, ClassificationResult>  $results  the item's result rows
     * @param  array{user: string, action: ?ActivityLog}|null  $human  see humanDecisions()
     * @return array{method: string, reason: string}
     */
    public function describe(ClassificationItem $item, Collection $results, ?array $human = null): array
    {
        $by = $results->keyBy('mechanism');
        $code = (string) $item->final_code;

        return match (true) {
            in_array($item->resolution, ['confirmed', 'rejected'], true) => $this->human($item, $human),
            $item->resolution === 'trash' => $this->line('trash', TrashFilter::explain((string) data_get($by->get('trash')?->trace, 'rule', ''))),
            $item->resolution === 'agreed' && $by->has('cache') => $this->memory($by->get('cache')),
            $item->resolution === 'agreed' => $this->consensus($code, $by),
            $item->resolution === 'ai_resolved' => $this->resolver($code, $by),
            $item->resolution === 'pending' => $this->line('in_progress', __('Still being classified.')),
            default => $this->undecided($item, $by),
        };
    }

    /** @return array{method: string, reason: string} */
    private function memory(ClassificationResult $cache): array
    {
        // New cache rows keep the memory row's provenance in the trace; older ones only in
        // the explanation ("Verified answer from the cache (auto:consensus).").
        $source = $cache->trace['source']
            ?? (preg_match('/\(([^()]+)\)\.?\s*$/u', (string) $cache->explanation, $m) ? $m[1] : null);

        if ($source === null) {
            return $this->line('memory', __('The name matched a verified answer in memory.'));
        }

        $from = match (true) {
            $source === (string) config('classify.memory_promotion.source', 'auto:consensus'),
            str_starts_with($source, 'auto:') => __('an earlier unanimous AI decision'),
            $source === (string) config('classify.memory_promotion.confirmed.source', 'confirmed') => __('a human confirmation'),
            $source === (string) config('classify.memory_promotion.grounded_search.source', 'ai_resolved_grounded') => __('a web search with sources'),
            default => __('reference labelling'), // the seeded gold / Fedor sets
        };

        return $this->line('memory', __('The name matched a verified answer in memory (source: :source).', ['source' => $from]));
    }

    /**
     * An 'agreed' item: Direct's answer sits in the vector's top-K shortlist (the rule in
     * Consensus::resolve) — name where it sits. Items agreed under an older rule (the
     * broker era) may not match it; they get the plain line.
     *
     * @param  Collection<string, ClassificationResult>  $by
     * @return array{method: string, reason: string}
     */
    private function consensus(string $code, Collection $by): array
    {
        $direct = $by->get('direct');
        $k = max(1, (int) config('classify.vector.membership_k', 3));

        foreach (array_slice((array) ($by->get('vector')?->candidates ?? []), 0, $k) as $i => $c) {
            if (is_array($c) && HeadingMatch::same($direct?->matched_code, $direct?->kind, $c['code'] ?? null, $c['kind'] ?? null)) {
                return $this->line('consensus', __('Two independent methods agree: the AI model (Direct) chose :code, and the catalog vector search ranks it #:pos of :k.', [
                    'code' => $code, 'pos' => $i + 1, 'k' => $k,
                ]));
            }
        }

        return $this->line('consensus', __('The AI methods agreed on :code.', ['code' => $code]));
    }

    /**
     * An 'ai_resolved' item: the divergence resolver settled it — the ensemble vote, or the
     * web search when the vote split. The step that decided is the one whose committed code
     * IS the final code; a re-run can overwrite a trace row after the item was settled, and
     * then no row matches.
     *
     * @param  Collection<string, ClassificationResult>  $by
     * @return array{method: string, reason: string}
     */
    private function resolver(string $code, Collection $by): array
    {
        $ensemble = $by->get('ensemble');
        if ($ensemble !== null && $ensemble->status === 'auto_confirmed' && (string) $ensemble->matched_code === $code) {
            $picks = array_values((array) data_get($ensemble->trace, 'picks', []));
            $reason = $picks === []
                ? __('The methods diverged; a repeat vote over the catalog shortlist chose :code.', ['code' => $code])
                : __('The methods diverged; a repeat vote over the catalog shortlist chose :code (:for of :total votes).', [
                    'code' => $code,
                    'for' => count(array_filter($picks, fn ($p) => (string) $p === $code)),
                    'total' => count($picks),
                ]);
            $identity = $this->clean((string) data_get($ensemble->trace, 'understanding.identity', ''));

            return $this->line('ensemble', $identity === '' ? $reason : $reason.' '.__('Understood as “:identity”.', ['identity' => $identity]));
        }

        $search = $by->get('search');
        if ($search !== null && $search->status === 'auto_confirmed' && (string) $search->matched_code === $code) {
            return $this->line('web_search', __('The methods diverged; a web search identified the item (confidence :pct).', [
                'pct' => $this->pct($search->confidence),
            ]).' '.$this->quote($search->explanation));
        }

        return $this->line('ai', __('The methods diverged; the code was chosen automatically, but its detailed trace was not kept.'));
    }

    /**
     * Still open: a conflict the resolver is working on or could not settle, no code at
     * all, or a missing fact (broker era).
     *
     * @param  Collection<string, ClassificationResult>  $by
     * @return array{method: string, reason: string}
     */
    private function undecided(ClassificationItem $item, Collection $by): array
    {
        if ($item->resolution === 'no_match') {
            return $this->line('needs_human', __('No method found a code — a human needs to decide.'));
        }
        if ($item->resolution === 'blocked_on_fact') {
            return $this->line('needs_human', __('A fact the code depends on is missing from the line — a human needs to decide.'));
        }

        // A conflict. The resolver always leaves a 'search' row when it finishes (the same
        // signal ClassificationItem::isResolving() reads) — none yet = still in flight.
        $search = $by->get('search');
        if ($search === null) {
            return (bool) config('classify.search_resolver.enabled', false)
                ? $this->line('in_progress', __('The methods diverged; the web search is still running.'))
                : $this->line('needs_human', __('The methods diverged — a human needs to decide.'));
        }
        if ($search->status === 'no_match') { // the resolver's call failed or timed out
            return $this->line('needs_human', __('The methods diverged and the web search was unavailable — a human needs to decide.'));
        }

        $reason = match (true) {
            $search->matched_code === null || $search->matched_code === '' => __('The methods diverged and the web search could not identify the item — a human needs to decide.'),
            $search->status !== 'auto_confirmed' => __('The methods diverged; the web search suggests :code but is not confident enough (:pct) — a human needs to decide.', [
                'code' => $search->matched_code, 'pct' => $this->pct($search->confidence),
            ]),
            default => __('The methods diverged — a human needs to decide.'),
        };

        return $this->line('needs_human', $reason.' '.$this->quote($search->explanation));
    }

    /**
     * @param  array{user: string, action: ?ActivityLog}|null  $human
     * @return array{method: string, reason: string}
     */
    private function human(ClassificationItem $item, ?array $human): array
    {
        $action = $human['action'] ?? null;
        $user = $human['user'] ?? __('a reviewer');

        if ($item->resolution === 'rejected') {
            return $this->line('human', $action?->action === 'classification.reject'
                ? __('Rejected by :user on :date.', ['user' => $user, 'date' => $action->created_at?->format('Y-m-d')])
                : __('Rejected by a reviewer.'));
        }

        $date = ($item->confirmed_at ?? $action?->created_at)?->format('Y-m-d') ?? '—';
        // A correction records the code it replaced; empty when the item had no code yet.
        $was = $action?->action === 'classification.corrected' ? (string) data_get($action->properties, 'was', '') : null;

        return $this->line('human', match (true) {
            $was === null || $was === (string) $item->final_code => __('Confirmed by :user on :date.', ['user' => $user, 'date' => $date]),
            $was === '' => __('Chosen by :user on :date.', ['user' => $user, 'date' => $date]),
            default => __('Corrected by :user on :date (was :was).', ['user' => $user, 'date' => $date, 'was' => $was]),
        });
    }

    /**
     * Who decided each confirmed / rejected item, and how — the item's last confirm /
     * correct / reject action in the audit trail. A bulk confirm or reject writes no
     * per-item action; then only confirmed_by is known.
     *
     * @param  Collection<int, ClassificationItem>  $items
     * @return array<int, array{user: string, action: ?ActivityLog}>
     */
    private function humanDecisions(Collection $items): array
    {
        $decided = $items->whereIn('resolution', ['confirmed', 'rejected']);
        if ($decided->isEmpty()) {
            return [];
        }

        $actions = collect();
        foreach ($decided->pluck('id')->chunk(1000) as $ids) {
            $actions = $actions->merge(ActivityLog::query()
                ->where('subject_type', (new ClassificationItem)->getMorphClass())
                ->whereIn('subject_id', $ids)
                ->whereIn('action', self::HUMAN_ACTIONS)
                ->orderBy('id')
                ->get(['subject_id', 'user_id', 'action', 'properties', 'created_at']));
        }
        $actions = $actions->keyBy('subject_id'); // ordered by id → the last action wins

        $users = User::whereIn('id', $decided->pluck('confirmed_by')->merge($actions->pluck('user_id'))->filter()->unique())
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name ?: $u->email]);

        $out = [];
        foreach ($decided as $item) {
            $action = $actions->get($item->id);
            $userId = $item->resolution === 'confirmed' ? ($item->confirmed_by ?? $action?->user_id) : $action?->user_id;
            $out[$item->id] = ['user' => ($userId !== null ? $users->get($userId) : null) ?? __('a reviewer'), 'action' => $action];
        }

        return $out;
    }

    /**
     * The model's own words for a spreadsheet cell, with the trailing " [web: host, …]"
     * citation note turned into a "Sources" line.
     */
    private function quote(?string $text): string
    {
        $text = (string) $text;
        $sources = preg_match('/\s*\[web:\s*([^\]]*)\]\s*$/u', $text, $m) ? trim($m[1]) : '';
        $text = $this->clean((string) preg_replace('/\s*\[web:\s*[^\]]*\]\s*$/u', '', $text));

        return trim(($text !== '' ? __('Model: “:text”', ['text' => $text]) : '')
            .($sources !== '' ? ' '.__('Sources: :list.', ['list' => $sources]) : ''));
    }

    /** Model text on one line: markdown links → their text (search models cite inline), whitespace collapsed. */
    private function clean(string $text): string
    {
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)\s]*\)/u', '$1', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function pct(?float $confidence): string
    {
        return $confidence === null ? '—' : round($confidence * 100).'%';
    }

    /** @return array{method: string, reason: string} */
    private function line(string $method, string $reason): array
    {
        return ['method' => $method, 'reason' => trim($reason)];
    }
}
