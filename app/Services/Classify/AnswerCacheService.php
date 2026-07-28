<?php

namespace App\Services\Classify;

use App\Models\AnswerCache;
use App\Models\ClassificationItem;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * Write a UNANIMOUS ensemble answer back into the PRODUCTION memory (scope 0) so an
     * identical name later resolves for free with no AI. Called by Consensus after an item
     * settles to 'agreed'; enforces the unanimity gate — every authoritative mechanism
     * that ran agreed on the winning heading, and at least min_agreement of them ran (a
     * plain 2-of-3 majority or a web-search resolution is deliberately NOT promoted).
     *
     * Fully error-isolated (like SearchCache): a write-back is a nice-to-have, it must
     * NEVER fail the classification queue or thrash a job. insertOrIgnore on the
     * (test_dataset_id, name_key) unique key makes a concurrent duplicate — or a name that
     * already has a seeded Fedor answer — a harmless no-op (never overwrites, never 23505).
     *
     * @param  array{count: int, total: int, heading: ?string, kind: ?string}  $agreement  from Consensus::agreementOf()
     */
    public function promote(ClassificationItem $item, array $agreement): void
    {
        $cfg = (array) config('classify.memory_promotion', []);
        if (! ($cfg['enabled'] ?? false)) {
            return;
        }

        $count = (int) ($agreement['count'] ?? 0);
        $total = (int) ($agreement['total'] ?? 0);
        $min = (int) ($cfg['min_agreement'] ?? 2);

        // Unanimity: every mechanism that ran agreed on ONE heading, and enough of them
        // ran to be a real independent corroboration (>= min_agreement).
        if ($total < $min || $count !== $total) {
            return;
        }

        $kind = (string) ($item->kind ?? '');
        $heading = (string) ($item->final_code ?? '');
        $isService = $kind === 'service';
        // A good must carry a real 4-digit heading; a service is stored heading-null.
        if (! $isService && ! preg_match('/^\d{4}$/', $heading)) {
            return;
        }
        $name = (string) $item->source_text;
        if (trim($name) === '') {
            return;
        }

        // Shadow rollout: record what WOULD be promoted, write nothing — so the real
        // volume/quality can be measured before the write-back is switched live.
        if ($cfg['shadow'] ?? true) {
            Log::info('memory_promotion.shadow', [
                'item_id' => $item->id,
                'name' => mb_substr($name, 0, 120),
                'heading' => $isService ? null : $heading,
                'is_service' => $isService,
                'agreement' => "{$count}/{$total}",
            ]);

            return;
        }

        try {
            AnswerCache::insertOrIgnore([
                'test_dataset_id' => 0, // production scope — the cache the live classifier reads
                'source' => (string) ($cfg['source'] ?? 'auto:consensus'),
                'name' => $name,
                'name_key' => AnswerCache::keyFor($name),
                'heading' => $isService ? null : $heading,
                'is_service' => $isService,
                'tier' => 'auto',
                'meta' => json_encode(['agreement' => "{$count}/{$total}", 'item_id' => $item->id],
                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // A write-back failure must never affect the (already settled) classification.
        }
    }
}
