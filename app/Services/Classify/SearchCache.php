<?php

namespace App\Services\Classify;

use App\Models\ItemTranslation;
use App\Models\LlmSearchCache;
use Throwable;

/**
 * Read/write cache for the web-search resolver's paid `:online` calls, keyed by
 * (model, prompt_version, item-name hash) — the same key shape as ProductBrief. Shared
 * by prod and test runs so an identical conflict never pays for the slow search twice.
 *
 * Both methods are FULLY error-isolated: a read failure degrades to a miss (the live
 * search still runs) and a write failure is swallowed (a successful, already-paid search
 * must never be lost to a cache error) — the resolver's contract is "abstain/degrade,
 * never block the queue", and a cache fault must not break it or thrash the reaper.
 */
class SearchCache
{
    /**
     * The cached provider response ({content, usage, model, annotations}) for this
     * (model, item) at the current prompt_version, or null on miss / disabled / any error.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(string $model, string $text): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        try {
            $row = LlmSearchCache::query()
                ->where('model', $model)
                ->where('prompt_version', $this->version())
                ->where('source_hash', ItemTranslation::hashFor($text))
                ->first();

            return $row?->response;
        } catch (Throwable) {
            return null; // read error → treat as a miss, run the live search
        }
    }

    /**
     * Persist a CONFIDENT search response. Idempotent + exception-safe: a concurrent
     * duplicate (two workers resolving the same name) is a no-op via insertOrIgnore, and
     * any write error is swallowed so it can never turn a paid success into a failure.
     *
     * @param  array<string, mixed>  $response  {content, usage, model, annotations}
     */
    public function store(string $model, string $text, array $response): void
    {
        if (! $this->enabled()) {
            return;
        }
        try {
            LlmSearchCache::insertOrIgnore([
                'model' => $model,
                'prompt_version' => $this->version(),
                'source_hash' => ItemTranslation::hashFor($text),
                'response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // A cache-write failure must never affect the (already successful) search.
        }
    }

    private function enabled(): bool
    {
        return (bool) config('classify.search_resolver.cache_enabled', true);
    }

    private function version(): string
    {
        return (string) config('classify.search_resolver.prompt_version', 's1');
    }
}
