<?php

namespace App\Console\Commands;

use App\Services\Embeddings\OllamaEmbedder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EmbedCatalog extends Command
{
    protected $signature = 'data:embed-catalog
        {--chunk=16 : Rows per embedding batch}
        {--reset : Re-embed everything (clears existing embeddings first)}';

    protected $description = 'Generate bge-m3 embeddings for catalog rows (resumable: only fills missing ones)';

    public function handle(OllamaEmbedder $embedder): int
    {
        if ($this->option('reset')) {
            DB::statement('UPDATE catalog SET embedding = NULL, embedded_at = NULL');
            $this->info('Cleared existing embeddings.');
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $base = DB::table('catalog')->whereNull('embedding');
        $total = (clone $base)->count();

        if ($total === 0) {
            $this->info('Nothing to embed — all catalog rows already have embeddings.');
            return self::SUCCESS;
        }

        $this->info("Embedding {$total} catalog rows (batch size {$chunk}) ...");
        $bar = $this->output->createProgressBar($total);

        $base->select('id', 'name')->orderBy('id')->chunkById($chunk, function ($rows) use ($embedder, $bar) {
            $vectors = $embedder->embed($rows->map(fn ($r) => $this->embedText($r->name))->all());

            DB::transaction(function () use ($rows, $vectors) {
                foreach ($rows->values() as $i => $row) {
                    DB::update(
                        'UPDATE catalog SET embedding = ?::vector, embedded_at = now() WHERE id = ?',
                        [OllamaEmbedder::toSqlVector($vectors[$i]), $row->id],
                    );
                }
            });

            $bar->advance($rows->count());
        });

        $bar->finish();
        $this->newLine(2);
        $remaining = DB::table('catalog')->whereNull('embedding')->count();
        $this->info('Done. Rows still missing an embedding: '.$remaining);

        return self::SUCCESS;
    }

    /**
     * The registry repeats the full HS path in every name; the discriminative
     * term is the last "–"-separated segment. Embedding that leaf is both faster
     * (shorter text) and more specific than embedding the whole boilerplate.
     */
    private function embedText(string $name): string
    {
        $segments = array_values(array_filter(array_map(
            'trim',
            preg_split('/–/u', $name) ?: [$name],
        )));

        $leaf = end($segments);

        return is_string($leaf) && $leaf !== '' ? $leaf : trim($name);
    }
}
