<?php

namespace App\Console\Commands;

use App\Models\LlmSearchCache;
use App\Models\LlmUsage;
use Illuminate\Console\Command;

// Hit-rate + estimated savings of the web-search cache, read cheaply from the
// llm_usage ledger (search_resolve rows: status 'cache' = hits, 'ok' = live calls).
class SearchCacheStats extends Command
{
    protected $signature = 'search-cache:stats';

    protected $description = 'Show web-search cache hit-rate and estimated saved tokens.';

    public function handle(): int
    {
        $entries = LlmSearchCache::count();
        $hits = LlmUsage::where('purpose', 'search_resolve')->where('status', 'cache')->count();
        $live = LlmUsage::where('purpose', 'search_resolve')->where('status', 'ok')->count();
        $total = $hits + $live;
        $rate = $total > 0 ? round(100 * $hits / $total, 1) : 0.0;
        // Estimate saved tokens as hits × the average tokens a live search actually spent.
        $avgLive = (float) LlmUsage::where('purpose', 'search_resolve')->where('status', 'ok')->avg('total_tokens');
        $saved = (int) round($hits * $avgLive);

        $this->info('Web-search cache');
        $this->line("  cached answers : {$entries}");
        $this->line("  hits / live    : {$hits} / {$live}");
        $this->line("  hit-rate       : {$rate}%");
        $this->line('  est. saved     : '.number_format($saved).' tokens (~'.number_format($avgLive).'/call)');

        return self::SUCCESS;
    }
}
