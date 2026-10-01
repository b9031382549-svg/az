<?php

namespace App\Services\Classify;

use App\Jobs\ClassifyMechanismJob;
use App\Jobs\SortItemsJob;
use App\Jobs\TranslateItemJob;
use App\Models\ClassificationItem;
use App\Models\ItemTranslation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

// Puts item names on the classification pipeline — the one path shared by the Classify page
// and invoice uploads: one parent ClassificationItem per unique (batch, name); a verified
// memory (answer cache) hit resolves it at once, a name that names no product at all is marked
// 'trash' (TrashFilter rules, then the Sorter model), every other name fans out one
// ClassifyMechanismJob per enabled mechanism; plus one background translation per name.
class ClassificationQueue
{
    /**
     * Rows per upsert and jobs per bulk push. Also keeps every insert far below Postgres'
     * 65,535 bind-parameter cap — a single upsert of ~11k+ names would exceed it.
     */
    private const CHUNK = 500;

    public function __construct(
        private readonly AnswerCacheService $cache,
        private readonly TrashFilter $trash,
        private readonly Sorter $sorter,
    ) {}

    /** Create the items and put them on the pipeline; returns the number of distinct items. */
    public function enqueue(array $texts, string $batch): int
    {
        $items = $this->createItems($texts, $batch);
        $this->dispatch($items);

        return $items->count();
    }

    /**
     * Create (or reuse) the parent rows for $texts in $batch WITHOUT dispatching anything, so a
     * caller can link its own rows to the items inside a transaction and dispatch() only after
     * it commits (a job must never pick up an item whose upload was rolled back).
     *
     * @param  array<int, string>  $texts
     * @return Collection<string, ClassificationItem> the items of $texts, keyed by source_hash
     */
    public function createItems(array $texts, string $batch): Collection
    {
        $now = now();

        // keyBy(source_hash) so one upsert never targets the same (batch, source_hash) twice.
        $rows = collect($texts)
            ->map(fn ($t) => [
                'batch' => $batch,
                'source_hash' => ItemTranslation::hashFor($t),
                'source_text' => $t,
                'resolution' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->keyBy('source_hash')
            ->values()
            ->all();

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            ClassificationItem::upsert($chunk, ['batch', 'source_hash'], ['source_text']);
        }

        // Only THESE names' items — a big upload writes its batch in portions, and reloading the
        // whole batch for every portion would grow quadratically.
        $items = collect();
        foreach (array_chunk(array_column($rows, 'source_hash'), 1000) as $hashes) {
            $items = $items->merge(ClassificationItem::where('batch', $batch)->whereIn('source_hash', $hashes)->get());
        }

        return $items->keyBy('source_hash');
    }

    /**
     * FIRST step: a verified answer in the cache resolves the item immediately — no mechanism
     * jobs, no LLM. SECOND: a cache miss whose name names no product (TrashFilter) is marked
     * 'trash', again with no AI. THIRD: the Sorter model reads what is left (SortItemsJob, on
     * the queue) — confident trash is settled the same way, every other verdict is stored for
     * Consensus — and only then does the rest fan out to the AI pipeline.
     *
     * @param  Collection<array-key, ClassificationItem>  $items
     */
    public function dispatch(Collection $items): void
    {
        $left = [];
        foreach ($items as $item) {
            // Absolute trash (only digits) wins even over a cached answer.
            if ($this->trash->apply($item, absoluteOnly: true) || $this->cache->apply($item) || $this->trash->apply($item)) {
                continue;
            }
            $left[] = $item;
        }
        // On the queue, not inline: neither a page request nor an upload's feed should wait
        // for a model on the CPU. Without the sorter the names go straight to the AI.
        $jobs = $this->sorter->enabled()
            ? array_map(
                fn (array $chunk) => new SortItemsJob(array_map(fn (ClassificationItem $i) => (int) $i->id, $chunk)),
                array_chunk($left, SortItemsJob::SIZE),
            )
            : $this->jobsFor($left);
        foreach (array_chunk($jobs, self::CHUNK) as $chunk) {
            Queue::bulk($chunk, '', 'default');
        }

        if (config('classify.translate_items', true)) {
            $translate = $items->pluck('source_text')->unique()
                ->map(fn ($t) => new TranslateItemJob((string) $t))->values()->all();
            foreach (array_chunk($translate, self::CHUNK) as $chunk) {
                Queue::bulk($chunk, '', 'default');
            }
        }
    }

    /**
     * A human's "not trash — classify it": put a trashed item back on the pipeline. Its 'trash'
     * trace row stays, marked 'overridden' — the filter never re-trashes the item and the
     * decision page still shows what happened. The automatic pipeline starts over, so
     * answered_at is cleared for it to be stamped again. Returns false when not trash, or when
     * the rule is absolute (only digits).
     */
    public function classifyAnyway(ClassificationItem $item): bool
    {
        if ($item->resolution !== 'trash') {
            return false;
        }
        // Only digits is always trash — no reviewer override for it.
        $rule = (string) data_get($item->results()->where('mechanism', 'trash')->first()?->trace, 'rule', '');
        if (TrashFilter::isAbsolute($rule)) {
            return false;
        }

        $item->results()->where('mechanism', 'trash')->update(['status' => 'overridden']);
        $item->update(['resolution' => 'pending', 'answered_at' => null]);

        // Memory first, as for any item — the same name may have been answered since.
        if (! $this->cache->apply($item)) {
            Queue::bulk($this->mechanismJobs($item), '', 'default');
        }

        return true;
    }

    /**
     * Put $items on the AI mechanisms — the step after the sorter (SortItemsJob).
     *
     * @param  array<int, ClassificationItem>  $items
     */
    public function toMechanisms(array $items): void
    {
        foreach (array_chunk($this->jobsFor($items), self::CHUNK) as $chunk) {
            Queue::bulk($chunk, '', 'default');
        }
    }

    /**
     * @param  array<int, ClassificationItem>  $items
     * @return array<int, ClassifyMechanismJob>
     */
    private function jobsFor(array $items): array
    {
        $jobs = [];
        foreach ($items as $item) {
            array_push($jobs, ...$this->mechanismJobs($item));
        }

        return $jobs;
    }

    /** @return array<int, ClassifyMechanismJob> one job per enabled mechanism */
    private function mechanismJobs(ClassificationItem $item): array
    {
        return array_map(
            fn ($mechanism) => new ClassifyMechanismJob((int) $item->id, (string) $mechanism),
            (array) config('classify.mechanisms.enabled', ['vector']),
        );
    }
}
