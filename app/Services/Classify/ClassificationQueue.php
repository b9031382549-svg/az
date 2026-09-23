<?php

namespace App\Services\Classify;

use App\Jobs\ClassifyMechanismJob;
use App\Jobs\TranslateItemJob;
use App\Models\ClassificationItem;
use App\Models\ItemTranslation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

// Puts item names on the classification pipeline — the one path shared by the Classify page
// and invoice uploads: one parent ClassificationItem per unique (batch, name); a verified
// memory (answer cache) hit resolves it at once, every miss fans out one ClassifyMechanismJob
// per enabled mechanism; plus one background translation per name.
class ClassificationQueue
{
    /**
     * Rows per upsert and jobs per bulk push. Also keeps every insert far below Postgres'
     * 65,535 bind-parameter cap — a single upsert of ~11k+ names would exceed it.
     */
    private const CHUNK = 500;

    public function __construct(
        private readonly AnswerCacheService $cache,
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
     * @return Collection<string, ClassificationItem> keyed by source_hash
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

        return ClassificationItem::where('batch', $batch)->get()->keyBy('source_hash');
    }

    /**
     * FIRST step: a verified answer in the cache resolves the item immediately — no mechanism
     * jobs, no LLM. Only cache MISSES fan out to the AI pipeline.
     *
     * @param  Collection<array-key, ClassificationItem>  $items
     */
    public function dispatch(Collection $items): void
    {
        $enabled = (array) config('classify.mechanisms.enabled', ['vector']);

        $jobs = [];
        foreach ($items as $item) {
            if ($this->cache->apply($item)) {
                continue;
            }
            foreach ($enabled as $mechanism) {
                $jobs[] = new ClassifyMechanismJob((int) $item->id, (string) $mechanism);
            }
        }
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
}
