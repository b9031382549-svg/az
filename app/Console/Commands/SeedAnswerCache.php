<?php

namespace App\Console\Commands;

use App\Models\AnswerCache;
use App\Models\GoldLabel;
use Illuminate\Console\Command;

/**
 * Seed the answer cache from a reference source (default: Fedor) already imported
 * into gold_labels. One verified answer per name (4-digit heading, or a service).
 * Idempotent (upsert by name_key). No LLM.
 */
class SeedAnswerCache extends Command
{
    protected $signature = 'cache:seed
        {--source=fedor : gold source to seed from}
        {--fresh : truncate the cache first}';

    protected $description = 'Seed the answer cache from the reference (Fedor) gold labels';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            AnswerCache::truncate();
            $this->warn('Truncated answer_cache.');
        }

        $source = (string) $this->option('source');
        $rows = GoldLabel::where('source', $source)->get()
            ->map(fn ($g) => [
                'source' => $g->source,
                'name' => $g->name,
                'name_key' => $g->name_key,
                'heading' => $g->is_service ? null : $g->heading,
                'is_service' => (bool) $g->is_service,
                'tier' => $g->tier,
                'meta' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->keyBy('name_key') // one row per name_key for the upsert
            ->values()
            ->all();

        if ($rows === []) {
            $this->error("No gold labels for source '{$source}'. Run benchmark:import-gold first.");

            return self::FAILURE;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            AnswerCache::upsert($chunk, ['name_key'], ['source', 'name', 'heading', 'is_service', 'tier', 'updated_at']);
        }

        $this->info('Seeded answer_cache: '.count($rows)." entries from '{$source}' (".AnswerCache::whereNotNull('heading')->count().' goods, '.AnswerCache::where('is_service', true)->count().' services).');

        return self::SUCCESS;
    }
}
