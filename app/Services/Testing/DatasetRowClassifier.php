<?php

namespace App\Services\Testing;

use App\Models\ClassificationItem;
use App\Services\Classify\AnswerCacheService;
use App\Services\Classify\Consensus;
use App\Services\Classify\Mechanisms\BrokerDescentMechanism;
use App\Services\Classify\Mechanisms\DirectLlmMechanism;
use App\Services\Classify\Mechanisms\VectorMechanism;
use App\Services\Classify\SearchResolverService;
use Throwable;

/**
 * Classifies ONE dataset test row by composing the SAME prod leaf-methods in the
 * SAME order as the live async flow — cache → mechanisms → Consensus → search — so
 * a test run reproduces production per-row output without a parallel pipeline.
 *
 * Only the orchestration is local (a synchronous loop instead of Horizon fan-out),
 * which is exactly what lets us (a) apply a per-run config snapshot in-process and
 * (b) score every mechanism independently. The classification LOGIC is untouched.
 */
class DatasetRowClassifier
{
    private const OFFLINE = ['vector', 'broker', 'direct'];

    public function __construct(
        private readonly AnswerCacheService $cache,
        private readonly Consensus $consensus,
        private readonly SearchResolverService $search,
    ) {}

    /**
     * @param  array{enabled:array<int,string>, shadow?:array<int,string>, cache?:bool, search?:bool}  $flags
     * @param  ?int  $datasetId  scopes the memory lookup to THIS dataset's cache (isolated from production)
     */
    public function run(ClassificationItem $item, array $flags, ?int $datasetId = null): void
    {
        // 1) memory first — prod short-circuits the rest on a cache hit. The lookup is
        //    scoped to the dataset's OWN cache so it never reads production memory.
        if (($flags['cache'] ?? false) && $this->cache->apply($item, $datasetId)) {
            return;
        }

        // 2) each enabled offline mechanism. A THROW must still leave an abstaining
        //    null-code row (mirrors ClassifyMechanismJob::failed) so the mechanism
        //    keeps its slot in the majority denominator — otherwise dropping two
        //    throwers turns a real conflict into a spurious 'agreed'.
        foreach ($flags['enabled'] as $key) {
            if (! in_array($key, self::OFFLINE, true)) {
                continue;
            }
            if ($item->results()->where('mechanism', $key)->exists()) {
                continue; // idempotent on a re-run of the same item
            }
            try {
                $result = app($this->mechanismClass($key))->classify((string) $item->source_text);
                $item->results()->updateOrCreate(['mechanism' => $key], $result->toRow());
            } catch (Throwable $e) {
                $item->results()->updateOrCreate(['mechanism' => $key], [
                    'status' => 'error',
                    'matched_code' => null,
                    'kind' => null,
                    'explanation' => mb_substr($e->getMessage(), 0, 500),
                ]);
            }
        }

        // 3) consensus over the authoritative subset — computed the SAME way
        //    Consensus::finalize() computes it (enabled − shadow).
        $authoritative = Consensus::computeAuthoritative(
            $flags['enabled'],
            $flags['shadow'] ?? [],
        );
        $item->update($this->consensus->resolve(
            $item->results()->whereIn('mechanism', $authoritative)->get()
        ));

        // 4) conflict → the paid web-search resolver, fired at most once (its :online
        //    call is not retried). It requires resolution IN (conflict, review), which
        //    step 3 just set, and flips via a whereKey()->update().
        if ($item->resolution === 'conflict'
            && ($flags['search'] ?? false)
            && ! $item->results()->where('mechanism', 'search')->exists()) {
            $this->search->resolve($item);
        }

        // The search flip is a query-builder update that never touches the in-memory
        // model — refresh so the scorer reads the real 'overall' (ai_resolved), not
        // the stale 'conflict'/null we set in step 3.
        $item->refresh();
    }

    private function mechanismClass(string $key): string
    {
        return match ($key) {
            'vector' => VectorMechanism::class,
            'broker' => BrokerDescentMechanism::class,
            'direct' => DirectLlmMechanism::class,
        };
    }
}
