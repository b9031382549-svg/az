<?php

namespace App\Console\Commands;

use App\Jobs\SearchResolveJob;
use App\Models\ClassificationItem;
use Illuminate\Console\Command;

/**
 * Recover conflict items whose search-resolve CLAIM was set but whose job never ran —
 * a crash/deploy-restart/lost-job in the tiny window between the Postgres claim
 * (search_resolved_at) and the Redis enqueue leaves the item latched 'conflict' with no
 * search ever done (the crashed job's own retry can't recover it — finalize() hits the
 * claim guard). An item whose search actually ran always has a mechanism='search' trace
 * row, so its ABSENCE past the job's timeout uniquely identifies an orphan — safe to
 * re-dispatch (the job re-checks 'conflict' and the trace uses updateOrCreate).
 */
class ReapSearchResolves extends Command
{
    protected $signature = 'classify:reap-search-resolves {--minutes=15 : re-dispatch claims older than this many minutes}';

    protected $description = 'Re-dispatch conflict items whose search-resolve claim never produced a result';

    public function handle(): int
    {
        if (! (bool) config('classify.search_resolver.enabled', false)) {
            $this->info('Search resolver disabled — nothing to reap.');

            return self::SUCCESS;
        }

        // Older than the job timeout so an in-flight resolve is never double-fired.
        $cutoff = now()->subMinutes(max(6, (int) $this->option('minutes')));

        $ids = ClassificationItem::query()
            ->where('resolution', 'conflict')
            ->whereNotNull('search_resolved_at')
            ->where('search_resolved_at', '<', $cutoff)
            ->whereDoesntHave('results', fn ($q) => $q->where('mechanism', 'search'))
            ->pluck('id');

        foreach ($ids as $id) {
            SearchResolveJob::dispatch((int) $id);
        }

        $this->info("Re-dispatched {$ids->count()} orphaned search-resolve claim(s).");

        return self::SUCCESS;
    }
}
