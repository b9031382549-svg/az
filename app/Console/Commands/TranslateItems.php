<?php

namespace App\Console\Commands;

use App\Models\Classification;
use App\Models\ItemTranslation;
use App\Services\Translate\ItemTranslator;
use Illuminate\Console\Command;

/**
 * Backfills the item-translation dictionary for items that were classified
 * before translation existed (or whose translation LLM call failed): sets the
 * missing source_hash on classifications, then translates every distinct item
 * that has no complete (en + ru) dictionary entry yet.
 */
class TranslateItems extends Command
{
    protected $signature = 'items:translate
        {--limit=0 : max distinct items to translate this run (0 = all)}';

    protected $description = 'Backfill uploaded-item display translations (en/ru) into item_translations';

    public function handle(ItemTranslator $translator): int
    {
        // 1) Backfill source_hash on classifications that predate the column.
        $backfilled = 0;
        Classification::query()
            ->whereNull('source_hash')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$backfilled) {
                foreach ($rows as $row) {
                    $row->update(['source_hash' => ItemTranslation::hashFor((string) $row->source_text)]);
                    $backfilled++;
                }
            });
        $this->info("source_hash backfilled on {$backfilled} classification(s).");

        // 2) Distinct items still missing a complete translation.
        $hashesDone = ItemTranslation::query()
            ->whereNotNull('en')->where('en', '!=', '')
            ->whereNotNull('ru')->where('ru', '!=', '')
            ->pluck('source_hash')
            ->flip();

        $items = Classification::query()
            ->whereNotNull('source_text')
            ->where('source_text', '!=', '')
            ->orderBy('id')
            ->pluck('source_text')
            ->map(fn ($t) => trim((string) $t))
            ->filter()
            ->unique(fn ($t) => ItemTranslation::hashFor($t))
            ->reject(fn ($t) => $hashesDone->has(ItemTranslation::hashFor($t)))
            ->values();

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $items = $items->take($limit);
        }

        $total = $items->count();
        if ($total === 0) {
            $this->info('Nothing to translate — dictionary is complete.');

            return self::SUCCESS;
        }

        $this->info("Translating {$total} distinct item(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $ok = 0;
        $failed = 0;
        foreach ($items as $text) {
            $row = $translator->ensure($text);
            if ($row && ($row->en ?? '') !== '' && ($row->ru ?? '') !== '') {
                $ok++;
            } else {
                $failed++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Translated: {$ok}, failed (left for retry): {$failed}.");

        return self::SUCCESS;
    }
}
