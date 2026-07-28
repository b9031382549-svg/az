<?php

namespace App\Console\Commands;

use App\Models\AnswerCache;
use Illuminate\Console\Command;

// Delete every auto-promoted production memory row (source 'auto:consensus'), leaving the
// seeded Fedor reference untouched. The escape hatch for the write-back: if auto-promotion
// ever proves to pollute, one command undoes ALL of it without harming the seed.
class RevertPromotedCache extends Command
{
    protected $signature = 'cache:revert-promoted
        {--source=auto:consensus : provenance tag to delete}
        {--force : skip the confirmation prompt}';

    protected $description = 'Delete all auto-promoted production answer_cache rows (keeps the seeded reference).';

    public function handle(): int
    {
        $source = (string) $this->option('source');
        $n = AnswerCache::where('test_dataset_id', 0)->where('source', $source)->count();

        if ($n === 0) {
            $this->info("No auto-promoted rows (source '{$source}') to delete.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$n} auto-promoted rows (source '{$source}')?")) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        AnswerCache::where('test_dataset_id', 0)->where('source', $source)->delete();
        $this->info("Deleted {$n} auto-promoted rows (source '{$source}').");

        return self::SUCCESS;
    }
}
