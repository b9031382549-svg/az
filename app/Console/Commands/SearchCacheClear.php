<?php

namespace App\Console\Commands;

use App\Models\LlmSearchCache;
use Illuminate\Console\Command;

// Flush the web-search resolver cache. Use after a prompt/model change if you want the
// old entries gone immediately (a prompt_version bump already invalidates them lazily).
class SearchCacheClear extends Command
{
    protected $signature = 'search-cache:clear';

    protected $description = 'Delete all cached web-search resolver answers (llm_search_cache).';

    public function handle(): int
    {
        $n = LlmSearchCache::count();
        LlmSearchCache::query()->delete();
        $this->info("Cleared {$n} cached search answers.");

        return self::SUCCESS;
    }
}
