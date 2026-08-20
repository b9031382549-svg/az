<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load fine-tuned catalog embeddings ({code,vector} JSONL from embed_with_model.py
 * on the GPU) into a SEPARATE catalog_ft table — the stock catalog.embedding is
 * never touched, so the baseline↔FT comparison is a clean A/B and rollback is just
 * leaving/renaming this table. Kept around deliberately for future tests.
 */
class CatalogLoadFt extends Command
{
    protected $signature = 'catalog:load-ft
        {--file=research-data/finetune/contrastive/catalog_ft_vectors.jsonl : {code,vector} JSONL}
        {--table=catalog_ft : destination table}';

    protected $description = 'Load fine-tuned {code,vector} embeddings into a separate catalog_ft(code,embedding) table.';

    public function handle(): int
    {
        $path = base_path((string) $this->option('file'));
        if (! is_file($path)) {
            $this->error("Vectors file not found: {$path}");

            return self::FAILURE;
        }
        $table = preg_replace('/[^a-z0-9_]/i', '', (string) $this->option('table'));

        DB::statement("CREATE TABLE IF NOT EXISTS {$table} (code varchar(255) PRIMARY KEY, embedding vector(1024))");
        DB::statement("TRUNCATE {$table}");

        $fh = fopen($path, 'r');
        $batch = [];
        $n = 0;
        $flush = function (array &$batch) use ($table) {
            if (! $batch) {
                return;
            }
            $values = [];
            $bind = [];
            foreach ($batch as [$code, $vec]) {
                $values[] = '(?, ?::vector)';
                $bind[] = $code;
                $bind[] = $vec;
            }
            DB::insert("INSERT INTO {$table} (code, embedding) VALUES ".implode(',', $values), $bind);
            $batch = [];
        };

        while (($line = fgets($fh)) !== false) {
            $row = json_decode($line, true);
            if (! isset($row['code'], $row['vector'])) {
                continue;
            }
            $batch[] = [(string) $row['code'], '['.implode(',', $row['vector']).']'];
            $n++;
            if (count($batch) >= 500) {
                $flush($batch);
            }
        }
        $flush($batch);
        fclose($fh);

        // HNSW so the FT index is reusable for any later ad-hoc test, not just this eval.
        DB::statement("CREATE INDEX IF NOT EXISTS {$table}_hnsw ON {$table} USING hnsw (embedding vector_cosine_ops)");

        $this->info("Loaded {$n} rows into {$table} (+ HNSW index).");

        return self::SUCCESS;
    }
}
