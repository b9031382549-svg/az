<?php

namespace App\Console\Commands;

use App\Models\CatalogCode;
use App\Support\AzFold;
use Illuminate\Console\Command;

/**
 * Rebuilds catalog.search_text = fold(name + ' ' + synonyms) for diacritic-
 * insensitive lexical retrieval. Idempotent; run after synonyms change (it reads
 * the current name + synonyms). The embeddings are not touched.
 */
class BuildSearchText extends Command
{
    protected $signature = 'catalog:build-search-text';

    protected $description = 'Rebuild the diacritic-folded search_text column from name + synonyms';

    public function handle(): int
    {
        $done = 0;
        CatalogCode::select(['id', 'name', 'synonyms'])->chunkById(1000, function ($rows) use (&$done) {
            foreach ($rows as $r) {
                $folded = AzFold::fold(trim((string) $r->name).' '.trim((string) ($r->synonyms ?? '')));
                CatalogCode::whereKey($r->id)->update(['search_text' => $folded]);
                $done++;
            }
        });

        $this->info("Built search_text for {$done} catalog rows.");

        return self::SUCCESS;
    }
}
