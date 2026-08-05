<?php

namespace App\Services\Classify;

use App\Models\AnswerCache;
use App\Models\ClassificationItem;
use App\Models\ClassificationResult;
use App\Models\TestRun;
use Illuminate\Support\Collection;
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

    /**
     * Write a human-CONFIRMED answer into the PRODUCTION memory — the strongest trust
     * signal in the system (a person made an explicit decision), so unlike promote() this
     * UPDATES an existing row when one is already there: if a human is correcting a stale
     * or wrong cache entry (fedor / gold / a prior auto-promotion), that fix must
     * propagate forward, not leave the same wrong answer to keep auto-resolving silently.
     * Called from ConfirmsClassifications::applyConfirm() after the item itself is saved.
     */
    public function promoteConfirmed(ClassificationItem $item): void
    {
        $cfg = (array) config('classify.memory_promotion.confirmed', []);
        if (! ($cfg['enabled'] ?? false)) {
            return;
        }

        [$name, $heading, $isService] = $this->normalizedAnswer($item->source_text, $item->kind, $item->final_code);
        if ($name === null) {
            return;
        }

        try {
            AnswerCache::updateOrCreate(
                ['test_dataset_id' => 0, 'name_key' => AnswerCache::keyFor($name)],
                [
                    'source' => (string) ($cfg['source'] ?? 'confirmed'),
                    'name' => $name,
                    'heading' => $heading,
                    'is_service' => $isService,
                    'tier' => 'human',
                    'meta' => json_encode(['item_id' => $item->id], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ],
            );
        } catch (Throwable) {
            // A write-back failure must never affect the (already applied) confirmation.
        }
    }

    /**
     * Write a GROUNDED search-resolved (ai_resolved) answer into memory — the PRODUCTION
     * cache (scope 0) for a live item, or the item's OWN DATASET memory for a Testing-run
     * item (so a Testing run's search discoveries never pollute prod — see groundedSearchScope()).
     * "Grounded" means the resolver's heading overlaps with at least one of the original
     * pre-conflict vector/broker/direct candidates AND its self-reported confidence clears
     * search_resolver.grounded_min_confidence — measured 93-96% real accuracy (vs ~64% for
     * the resolver's bare min_confidence=0.8 gate alone), comparable to the unanimous tier.
     * An AUTOMATED write, so — unlike promoteConfirmed() — insertOrIgnore: never allowed
     * to overwrite ANY existing verified answer regardless of source. Called from
     * SearchResolverService::resolve() once the item is confidently resolved.
     *
     * @param  Collection<int, ClassificationResult>  $authoritativeResults  the original
     *                                                                       pre-conflict vector/broker/direct rows, to check grounding against.
     */
    public function promoteGroundedSearch(ClassificationItem $item, ClassificationResult $search, Collection $authoritativeResults): void
    {
        $cfg = (array) config('classify.memory_promotion.grounded_search', []);
        if (! ($cfg['enabled'] ?? false)) {
            return;
        }

        if ($search->matched_code === null || $search->confidence === null) {
            return;
        }
        $minConf = (float) config('classify.search_resolver.grounded_min_confidence', 0.98);
        if ($search->confidence < $minConf) {
            return;
        }
        $heading = mb_substr((string) $search->matched_code, 0, 4);
        if (! Consensus::headingOverlaps($heading, $authoritativeResults)) {
            return;
        }

        [$name, $heading, $isService] = $this->normalizedAnswer($item->source_text, $search->kind, $heading);
        if ($name === null) {
            return;
        }

        // Scope the write: a prod item → production memory (scope 0); a Testing-run item →
        // its run's OWN dataset memory, so a test run's search discoveries build that
        // dataset's flywheel instead of leaking into (and lowering the quality of) prod.
        $scope = $this->groundedSearchScope($item);

        try {
            AnswerCache::insertOrIgnore([
                'test_dataset_id' => $scope,
                'source' => (string) ($cfg['source'] ?? 'ai_resolved_grounded'),
                'name' => $name,
                'name_key' => AnswerCache::keyFor($name),
                'heading' => $heading,
                'is_service' => $isService,
                'tier' => 'grounded',
                'meta' => json_encode(['confidence' => $search->confidence, 'item_id' => $item->id],
                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // A write-back failure must never affect the (already applied) resolution.
        }
    }

    /**
     * Which answer_cache scope a grounded promotion targets for this item: a live/prod
     * item (no test_run_id) → 0 (production memory, the only scope the live classifier
     * reads); a Testing-run item → that run's dataset, so its search discoveries warm the
     * DATASET's own isolated memory and never touch production.
     */
    private function groundedSearchScope(ClassificationItem $item): int
    {
        if ($item->test_run_id === null) {
            return 0;
        }

        return (int) (TestRun::whereKey($item->test_run_id)->value('test_dataset_id') ?? 0);
    }

    /**
     * Wipe the PRODUCTION memory (scope 0) back down to just the baseline reference
     * (config('classify.cache.baseline_source'), 'gold' by default) — deletes every
     * fedor / ivan / auto:consensus / confirmed / ai_resolved_grounded / ... row added
     * since. Test-dataset memory (test_dataset_id > 0 — DatasetMemory's own isolated
     * area, never read by the live classifier) is untouched, so resetting from the
     * main classifier can never affect a Testing subsystem measurement. Returns the
     * number of rows deleted.
     */
    public function resetToBaseline(): int
    {
        return AnswerCache::where('test_dataset_id', 0)
            ->where('source', '!=', (string) config('classify.cache.baseline_source', 'gold'))
            ->delete();
    }

    /**
     * Shared validation for a would-be answer_cache row: a real name, a service flagged
     * heading-null, or a good with a genuine 4-digit heading. Returns [null, null, null]
     * when the answer isn't well-formed enough to trust into memory.
     *
     * @return array{0: ?string, 1: ?string, 2: bool}
     */
    private function normalizedAnswer(?string $sourceText, ?string $kind, ?string $code): array
    {
        $name = (string) $sourceText;
        if (trim($name) === '') {
            return [null, null, false];
        }

        $isService = $kind === 'service';
        $heading = $isService ? null : mb_substr((string) $code, 0, 4);
        if (! $isService && ! preg_match('/^\d{4}$/', (string) $heading)) {
            return [null, null, false];
        }

        return [$name, $heading, $isService];
    }
}
