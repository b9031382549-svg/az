<?php

namespace App\Console\Commands;

use App\Models\Classification;
use App\Models\ClassificationItem;
use App\Models\ItemTranslation;
use App\Services\Llm\OpenRouterClient;
use App\Services\Translate\ItemTranslator;
use Illuminate\Console\Command;

/**
 * Backfills the item-translation dictionary for items that were classified
 * before translation existed (or whose translation LLM call failed): sets the
 * missing source_hash on classifications, then translates every distinct item
 * that has no complete (en + ru) dictionary entry yet. --redo-model re-translates
 * the rows one model wrote (e.g. Llama's, once a better route exists).
 */
class TranslateItems extends Command
{
    protected $signature = 'items:translate
        {--limit=0 : max distinct items to translate this run (0 = all)}
        {--fresh : re-translate every item, overwriting existing translations}
        {--redo-model= : re-translate the items this model translated (base = Llama on our GPU)}';

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

        // 2) Distinct items to translate. --fresh redoes the whole dictionary and
        //    --redo-model the rows one model wrote (both overwrite); otherwise only
        //    names without a complete (en + ru) entry: a failed call leaves its row
        //    empty, a name whose job never ran has no row at all.
        $fresh = (bool) $this->option('fresh');
        $redo = trim((string) $this->option('redo-model'));

        if ($fresh || $redo !== '') {
            $items = ItemTranslation::query()
                ->when($redo !== '', fn ($q) => $q->where('model', $redo))
                ->orderBy('id')
                ->pluck('source_text');
        } else {
            $hashesDone = ItemTranslation::query()
                ->whereNotNull('en')->where('en', '!=', '')
                ->whereNotNull('ru')->where('ru', '!=', '')
                ->pluck('source_hash')
                ->flip();

            // Names live in the dictionary itself, in the pipeline's items (Testing-tab runs
            // are never translated) and in the legacy classifications table.
            $items = ItemTranslation::query()->orderBy('id')->pluck('source_text')
                ->merge(ClassificationItem::query()->whereNull('test_run_id')->distinct()->pluck('source_text'))
                ->merge(Classification::query()->whereNotNull('source_text')->orderBy('id')->pluck('source_text'))
                ->reject(fn ($t) => $hashesDone->has(ItemTranslation::hashFor(trim((string) $t))));
        }

        $items = $items
            ->map(fn ($t) => trim((string) $t))
            ->filter()
            ->unique(fn ($t) => ItemTranslation::hashFor($t))
            ->values();

        // Redoing a model's rows while the route still leads to that same model would only
        // write the same translations again.
        if ($redo !== '') {
            $route = app(OpenRouterClient::class)->resolveProvider((string) config('classify.translate_model'), false);
            if ($route['model'] === $redo) {
                $this->warn("Translations still go to {$route['name']} ({$redo}) — the redo will reproduce them.");
            }
        }

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
            $row = $translator->ensure($text, $fresh || $redo !== '');
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
