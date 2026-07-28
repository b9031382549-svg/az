<?php

namespace App\Console\Commands;

use App\Models\AnswerCache;
use Illuminate\Console\Command;

// How much of the production memory (scope 0) is auto-promoted vs seeded, so the
// write-back's footprint stays observable. Auto rows carry source 'auto:consensus'
// (see AnswerCacheService::promote); everything else is the seeded Fedor reference.
class PromotedCacheStats extends Command
{
    protected $signature = 'cache:promoted-stats {--source=auto:consensus : provenance tag of the auto-promoted rows}';

    protected $description = 'Show how many production answer_cache rows were auto-promoted from unanimous consensus.';

    public function handle(): int
    {
        $source = (string) $this->option('source');
        $prod = AnswerCache::where('test_dataset_id', 0);

        $total = (clone $prod)->count();
        $auto = (clone $prod)->where('source', $source)->count();
        $goods = (clone $prod)->where('source', $source)->whereNotNull('heading')->count();
        $services = (clone $prod)->where('source', $source)->where('is_service', true)->count();

        $this->info('Production answer_cache (scope 0)');
        $this->line("  total rows      : {$total}");
        $this->line("  auto-promoted   : {$auto}  (source '{$source}')");
        $this->line("    goods         : {$goods}");
        $this->line("    services      : {$services}");
        $this->line('  seeded / other  : '.($total - $auto));

        return self::SUCCESS;
    }
}
