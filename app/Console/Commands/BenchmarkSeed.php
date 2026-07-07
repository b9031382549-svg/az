<?php

namespace App\Console\Commands;

use App\Jobs\ClassifyMechanismJob;
use App\Jobs\TranslateItemJob;
use App\Models\ClassificationItem;
use App\Models\GoldLabel;
use App\Models\ImportBatch;
use App\Models\ItemTranslation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

/**
 * Feed the reference ("gold") product names through our own classifier so the
 * /benchmark page can show how well we hit them. Creates a ClassificationItem per
 * gold name (batch "gold-<source>") and fans out the normal ClassifyMechanismJob
 * pipeline — the same path as a real upload. This DOES enqueue paid LLM work; it
 * classifies nothing on its own until you run it. Idempotent per (batch, name).
 */
class BenchmarkSeed extends Command
{
    protected $signature = 'benchmark:seed
        {source : ivan|fedor}
        {--limit=0 : max names to enqueue (0 = all)}
        {--only-missing : skip names we have already classified}';

    protected $description = 'Classify the gold reference names through our pipeline (for benchmarking)';

    private const CHUNK = 500;

    public function handle(): int
    {
        $source = $this->argument('source');
        if (! in_array($source, ['ivan', 'fedor'], true)) {
            $this->error("source must be 'ivan' or 'fedor'.");

            return self::FAILURE;
        }

        $batch = "gold-{$source}";
        $limit = (int) $this->option('limit');

        $names = GoldLabel::where('source', $source)
            ->orderBy('id')
            ->pluck('name')
            ->unique(fn ($n) => GoldLabel::keyFor((string) $n));

        if ($this->option('only-missing')) {
            $seen = ClassificationItem::pluck('source_text')
                ->map(fn ($t) => GoldLabel::keyFor((string) $t))
                ->flip();
            $names = $names->reject(fn ($n) => $seen->has(GoldLabel::keyFor((string) $n)));
        }

        if ($limit > 0) {
            $names = $names->take($limit);
        }
        $names = $names->values();

        if ($names->isEmpty()) {
            $this->info('Nothing to enqueue.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Enqueue {$names->count()} '{$source}' names for classification (batch {$batch})? This spends LLM calls.", true)) {
            return self::SUCCESS;
        }

        ImportBatch::updateOrCreate(
            ['key' => $batch],
            ['label' => "Gold: {$source}", 'source' => 'benchmark', 'item_count' => $names->count()],
        );

        $count = $this->enqueue($names->all(), $batch);
        $this->info("Enqueued {$count} items on batch {$batch}. Watch Horizon; then open /benchmark.");

        return self::SUCCESS;
    }

    /**
     * Mirror of the upload path: upsert one parent per (batch, source_hash), then
     * fan out a ClassifyMechanismJob per enabled mechanism + a translation job.
     *
     * @param  array<int, string>  $texts
     */
    private function enqueue(array $texts, string $batch): int
    {
        $enabled = (array) config('classify.mechanisms.enabled', ['vector']);
        $now = now();

        $rows = collect($texts)
            ->map(fn ($t) => [
                'batch' => $batch,
                'source_hash' => ItemTranslation::hashFor($t),
                'source_text' => $t,
                'resolution' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->keyBy('source_hash')
            ->values()
            ->all();

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            ClassificationItem::upsert($chunk, ['batch', 'source_hash'], ['source_text']);
        }

        $ids = ClassificationItem::where('batch', $batch)->where('resolution', 'pending')->pluck('id');

        $jobs = [];
        foreach ($ids as $id) {
            foreach ($enabled as $mechanism) {
                $jobs[] = new ClassifyMechanismJob((int) $id, (string) $mechanism);
            }
        }
        foreach (array_chunk($jobs, self::CHUNK) as $chunk) {
            Queue::bulk($chunk, '', 'default');
        }

        if (config('classify.translate_items', true)) {
            $translate = collect($texts)->unique()->map(fn ($t) => new TranslateItemJob($t))->all();
            foreach (array_chunk($translate, self::CHUNK) as $chunk) {
                Queue::bulk($chunk, '', 'default');
            }
        }

        return $ids->count();
    }
}
