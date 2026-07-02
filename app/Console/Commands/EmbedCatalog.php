<?php

namespace App\Console\Commands;

use App\Jobs\GenerateCatalogEmbeddings;
use App\Services\Embeddings\CatalogEmbeddingRunner;
use Illuminate\Console\Command;

class EmbedCatalog extends Command
{
    protected $signature = 'data:embed-catalog
        {--chunk=16 : Rows per embedding batch}
        {--reset : Clear all embeddings first (leaves a gap until refilled)}
        {--refresh : Re-embed every row in place (no gap; overwrites existing vectors)}
        {--queue : Dispatch to the queue (runs on the worker) instead of running inline}';

    protected $description = 'Generate bge-m3 embeddings for catalog rows (resumable; --queue runs it in the background)';

    public function handle(CatalogEmbeddingRunner $runner): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        if ($this->option('reset')) {
            $runner->clear();
            $this->info('Cleared existing embeddings.');
        }

        if ($this->option('refresh')) {
            return $this->refresh($runner, $chunk);
        }

        $pending = $runner->pendingCount();
        if ($pending === 0) {
            $this->info('Nothing to embed — all catalog rows already have embeddings.');

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            GenerateCatalogEmbeddings::dispatch($chunk);
            $this->info("Dispatched embedding of {$pending} rows to the queue (processed by the worker).");

            return self::SUCCESS;
        }

        $this->info("Embedding {$pending} catalog rows (batch size {$chunk}) ...");
        $bar = $this->output->createProgressBar($pending);

        while (($done = $runner->embedBatch($chunk)) > 0) {
            $bar->advance($done);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Done. Rows still missing an embedding: '.$runner->pendingCount());

        return self::SUCCESS;
    }

    private function refresh(CatalogEmbeddingRunner $runner, int $chunk): int
    {
        $before = now()->toDateTimeString();

        if ($this->option('queue')) {
            GenerateCatalogEmbeddings::dispatch($chunk, $before);
            $this->info('Dispatched an in-place re-embed of all catalog rows to the queue.');

            return self::SUCCESS;
        }

        $total = $runner->staleCount($before);
        $this->info("Re-embedding {$total} catalog rows in place ...");
        $bar = $this->output->createProgressBar($total);

        while (($done = $runner->refreshBatch($before, $chunk)) > 0) {
            $bar->advance($done);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Re-embed complete.');

        return self::SUCCESS;
    }
}
