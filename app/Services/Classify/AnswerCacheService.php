<?php

namespace App\Services\Classify;

use App\Models\AnswerCache;
use App\Models\ClassificationItem;

/**
 * The FIRST step of classification: look the item up in the verified answer cache
 * (seeded from the Fedor reference). A hit resolves the item immediately — a 4-digit
 * heading (or a service) we are confident in — with NO AI calls; a miss falls through
 * to the mechanism pipeline.
 *
 * Lookup is an exact normalized-name match for now; semantic (vector) lookup over the
 * reserved `answer_cache.embedding` column is planned.
 */
class AnswerCacheService
{
    public function enabled(): bool
    {
        return (bool) config('classify.cache.enabled', true);
    }

    /**
     * The cached answer for a name, or null. Scope 0 (default) is the PRODUCTION
     * cache — the only rows the live classifier ever sees; a positive $datasetId
     * looks up that dataset's OWN memory (used by a memory-on test run so its cache
     * stays isolated from production and from other datasets).
     */
    public function lookup(string $text, ?int $datasetId = null): ?AnswerCache
    {
        if (! $this->enabled() || trim($text) === '') {
            return null;
        }

        return AnswerCache::where('test_dataset_id', $datasetId ?? 0)
            ->where('name_key', AnswerCache::keyFor($text))
            ->first();
    }

    /**
     * Resolve an item from the cache if its name is known. Writes a 'cache' trace row
     * and sets the item's resolution — a good becomes its 4-digit heading, a service
     * the "99" service level, both confident. Returns true when it was a cache hit.
     * $datasetId scopes the lookup (null/0 = production; see lookup()).
     */
    public function apply(ClassificationItem $item, ?int $datasetId = null): bool
    {
        $hit = $this->lookup((string) $item->source_text, $datasetId);
        if ($hit === null) {
            return false;
        }

        $kind = $hit->is_service ? 'service' : 'good';
        $code = $hit->is_service ? '99' : $hit->heading; // 4-digit heading, or the "99" service level

        // A trace row so the review/decision page shows the answer came from the cache.
        $item->results()->updateOrCreate(
            ['mechanism' => 'cache'],
            [
                'matched_code' => $code,
                'catalog_id' => null,
                'kind' => $kind,
                'status' => 'auto_confirmed',
                'confidence' => 1.0,
                'candidates' => [],
                'explanation' => "Verified answer from the cache ({$hit->source}).",
                'model' => null,
            ],
        );

        $item->update([
            'resolution' => 'agreed',
            'final_code' => $code,
            'final_catalog_id' => null,
            'kind' => $kind,
        ]);

        return true;
    }
}
