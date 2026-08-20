<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Build a parallel `ft` schema whose `catalog` and `precedents` carry the
 * FINE-TUNED embeddings, so the full vector mechanism can run against them by
 * flipping the connection search_path to `ft,public` — no retriever code change,
 * stock tables untouched. ft.catalog is a full clone of public.catalog (all
 * columns the retriever selects) with only `embedding` swapped for the FT vector;
 * ft.precedents is (hs6, embedding) — all the precedent queries read.
 */
class ClassifyBuildFtSchema extends Command
{
    protected $signature = 'classify:build-ft-schema
        {--catalog=research-data/finetune/contrastive/catalog_ft_vectors.jsonl : {code,vector}}
        {--precedents=research-data/finetune/contrastive/precedents_ft_vectors.jsonl : {hs6,vector}}';

    protected $description = 'Build ft.catalog + ft.precedents (fine-tuned embeddings) for a search_path-swapped mechanism run.';

    public function handle(): int
    {
        $catPath = base_path((string) $this->option('catalog'));
        $prePath = base_path((string) $this->option('precedents'));
        foreach ([$catPath, $prePath] as $p) {
            if (! is_file($p)) {
                $this->error("Missing: {$p}");

                return self::FAILURE;
            }
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS ft');

        // ft.catalog: full clone of public.catalog, embeddings swapped to FT.
        $this->info('Cloning public.catalog → ft.catalog ...');
        DB::statement('DROP TABLE IF EXISTS ft.catalog');
        DB::statement('CREATE TABLE ft.catalog (LIKE public.catalog INCLUDING DEFAULTS)');
        DB::statement('INSERT INTO ft.catalog SELECT * FROM public.catalog');
        DB::statement('ALTER TABLE ft.catalog ADD PRIMARY KEY (id)');
        $this->updateVectors($catPath, fn ($code, $vec) => DB::update(
            'UPDATE ft.catalog SET embedding = ?::vector WHERE code = ?', [$vec, $code]
        ), 'code');

        // ft.precedents: just hs6 + FT embedding (all the retriever reads).
        $this->info('Building ft.precedents ...');
        DB::statement('DROP TABLE IF EXISTS ft.precedents');
        DB::statement('CREATE TABLE ft.precedents (hs6 varchar(6) NOT NULL, embedding vector(1024))');
        $this->insertPrecedents($prePath);

        $this->info('Indexing (HNSW) ...');
        DB::statement('CREATE INDEX ft_catalog_hnsw ON ft.catalog USING hnsw (embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX ft_catalog_strgm ON ft.catalog USING gin (search_text gin_trgm_ops)');
        DB::statement('CREATE INDEX ft_precedents_hnsw ON ft.precedents USING hnsw (embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX ft_precedents_hs6 ON ft.precedents (hs6)');

        $c = DB::table('ft.catalog')->whereNotNull('embedding')->count();
        $p = DB::table('ft.precedents')->count();
        $this->info("Done. ft.catalog embedded rows: {$c} | ft.precedents rows: {$p}");

        return self::SUCCESS;
    }

    private function updateVectors(string $path, callable $apply, string $key): void
    {
        $fh = fopen($path, 'r');
        $n = 0;
        DB::beginTransaction();
        while (($line = fgets($fh)) !== false) {
            $row = json_decode($line, true);
            if (! isset($row[$key], $row['vector'])) {
                continue;
            }
            $apply((string) $row[$key], '['.implode(',', $row['vector']).']');
            if (++$n % 2000 === 0) {
                DB::commit();
                DB::beginTransaction();
                $this->output->write("\r  updated {$n}   ");
            }
        }
        DB::commit();
        fclose($fh);
        $this->newLine();
    }

    private function insertPrecedents(string $path): void
    {
        $fh = fopen($path, 'r');
        $batch = [];
        $n = 0;
        $flush = function (array &$b) {
            if (! $b) {
                return;
            }
            $vals = [];
            $bind = [];
            foreach ($b as [$hs6, $vec]) {
                $vals[] = '(?, ?::vector)';
                $bind[] = $hs6;
                $bind[] = $vec;
            }
            DB::insert('INSERT INTO ft.precedents (hs6, embedding) VALUES '.implode(',', $vals), $bind);
            $b = [];
        };
        while (($line = fgets($fh)) !== false) {
            $row = json_decode($line, true);
            if (! isset($row['hs6'], $row['vector'])) {
                continue;
            }
            $batch[] = [(string) $row['hs6'], '['.implode(',', $row['vector']).']'];
            if (count($batch) >= 500) {
                $flush($batch);
                $n += 500;
                $this->output->write("\r  inserted {$n}   ");
            }
        }
        $flush($batch);
        fclose($fh);
        $this->newLine();
    }
}
