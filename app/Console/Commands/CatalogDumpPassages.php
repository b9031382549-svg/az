<?php

namespace App\Console\Commands;

use App\Models\CatalogCode;
use App\Services\Embeddings\CatalogEmbeddingRunner;
use App\Support\CatalogLeaf;
use Illuminate\Console\Command;

/**
 * Dump {code, text} for every catalog row, where `text` is the EXACT passage the
 * embedder indexes (CatalogEmbeddingRunner::passage — clipped name + synonyms).
 * The GPU box embeds this file with the fine-tuned model into catalog_ft, so the
 * FT index and the stock catalog index differ ONLY by the model, never by the text.
 */
class CatalogDumpPassages extends Command
{
    protected $signature = 'catalog:dump-passages
        {--out=research-data/finetune/contrastive/catalog_passages.jsonl : output JSONL}';

    protected $description = 'Dump {code,text} catalog passages (exact indexed text) for a fine-tuned re-embed on the GPU.';

    public function handle(CatalogEmbeddingRunner $runner): int
    {
        $out = base_path((string) $this->option('out'));
        @mkdir(dirname($out), 0775, true);
        $fh = fopen($out, 'w');
        $n = 0;

        CatalogCode::select(['code', 'name', 'synonyms'])->orderBy('code')
            ->chunk(1000, function ($rows) use ($runner, $fh, &$n) {
                foreach ($rows as $r) {
                    fwrite($fh, json_encode([
                        'code' => $r->code,
                        'heading' => substr((string) $r->code, 0, 4),
                        'text' => $runner->passage((string) $r->name, $r->synonyms),
                        'misc' => CatalogLeaf::isMisc((string) $r->name),
                    ], JSON_UNESCAPED_UNICODE)."\n");
                    $n++;
                }
            });

        fclose($fh);
        $this->info("Wrote {$n} passages → {$out}");

        return self::SUCCESS;
    }
}
