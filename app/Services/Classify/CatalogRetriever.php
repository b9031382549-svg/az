<?php

namespace App\Services\Classify;

use App\Services\Embeddings\OllamaEmbedder;
use Illuminate\Support\Facades\DB;

class CatalogRetriever
{
    public function __construct(private readonly OllamaEmbedder $embedder) {}

    /**
     * Hybrid candidate retrieval: semantic (pgvector cosine) + lexical (trigram
     * word similarity), fused with Reciprocal Rank Fusion.
     *
     * @return array<int, object{id:int, code:string, name:string, kind:string, score:float}>
     */
    public function candidates(string $text, int $limit = 24, ?string $kind = null): array
    {
        $per = max($limit, 30);
        $kindSql = $kind ? 'AND kind = ?' : '';
        $kindBind = $kind ? [$kind] : [];

        $vector = OllamaEmbedder::toSqlVector($this->embedder->embedOne($text));

        $semantic = DB::select(
            "SELECT id, code, name, kind, 1 - (embedding <=> ?::vector) AS sim
             FROM catalog
             WHERE embedding IS NOT NULL {$kindSql}
             ORDER BY embedding <=> ?::vector
             LIMIT {$per}",
            array_merge([$vector], $kindBind, [$vector]),
        );

        $lexical = DB::select(
            "SELECT id, code, name, kind, word_similarity(?, name) AS sim
             FROM catalog
             WHERE TRUE {$kindSql}
             ORDER BY word_similarity(?, name) DESC
             LIMIT {$per}",
            array_merge([$text], $kindBind, [$text]),
        );

        return $this->fuse($semantic, $lexical, $limit);
    }

    /**
     * @param  array<int, object>  $semantic
     * @param  array<int, object>  $lexical
     * @return array<int, object>
     */
    private function fuse(array $semantic, array $lexical, int $limit): array
    {
        $k = 60; // RRF damping
        $scores = [];
        $rows = [];

        foreach ([$semantic, $lexical] as $list) {
            foreach (array_values($list) as $rank => $row) {
                $scores[$row->id] = ($scores[$row->id] ?? 0) + 1 / ($k + $rank + 1);
                $rows[$row->id] = $row;
            }
        }

        arsort($scores);

        $out = [];
        foreach (array_slice(array_keys($scores), 0, $limit) as $id) {
            $row = $rows[$id];
            $row->score = round($scores[$id], 5);
            $out[] = $row;
        }

        return $out;
    }
}
