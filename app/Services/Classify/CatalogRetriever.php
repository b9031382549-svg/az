<?php

namespace App\Services\Classify;

use App\Services\Embeddings\OllamaEmbedder;
use Illuminate\Support\Facades\DB;

class CatalogRetriever
{
    public function __construct(private readonly OllamaEmbedder $embedder) {}

    /**
     * Hybrid candidate retrieval: semantic (pgvector cosine) + lexical (token
     * overlap ranked by trigram similarity), fused with Reciprocal Rank Fusion.
     *
     * @return array<int, object{id:int, code:string, name:string, kind:string, score:float}>
     */
    public function candidates(string $text, int $limit = 24, ?string $kind = null): array
    {
        $per = max($limit, 30);
        $kindSql = $kind ? 'AND kind = ?' : '';
        $kindBind = $kind ? [$kind] : [];

        $vector = OllamaEmbedder::toSqlVector($this->embedder->embedOne($this->normalize($text)));

        $semantic = $this->semantic($vector, $per, $kindSql, $kindBind);
        $lexical = $this->lexical($text, $per, $kindSql, $kindBind);

        $fused = $this->fuse($semantic, $lexical, $limit);
        $this->attachSemanticSim($fused, $vector);

        return $fused;
    }

    /**
     * Cosine similarity of the query to every candidate's embedding — the
     * retrieval signal used to gate auto-confirmation against an over-confident
     * LLM, regardless of which retriever surfaced the candidate.
     *
     * @param  array<int, object>  $candidates
     */
    private function attachSemanticSim(array $candidates, string $vector): void
    {
        if (empty($candidates)) {
            return;
        }

        $ids = array_map(fn ($c) => $c->id, $candidates);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sims = DB::select(
            "SELECT id, 1 - (embedding <=> ?::vector) AS sim
             FROM catalog WHERE id IN ({$placeholders}) AND embedding IS NOT NULL",
            array_merge([$vector], $ids),
        );

        $map = [];
        foreach ($sims as $row) {
            $map[$row->id] = round((float) $row->sim, 4);
        }

        foreach ($candidates as $candidate) {
            $candidate->semantic_sim = $map[$candidate->id] ?? null;
        }
    }

    /** @return array<int, object> */
    private function semantic(string $vector, int $per, string $kindSql, array $kindBind): array
    {
        return DB::select(
            "SELECT id, code, name, kind, 1 - (embedding <=> ?::vector) AS sim
             FROM catalog
             WHERE embedding IS NOT NULL {$kindSql}
             ORDER BY embedding <=> ?::vector
             LIMIT {$per}",
            array_merge([$vector], $kindBind, [$vector]),
        );
    }

    /**
     * Lexical retrieval gated on the query's significant tokens, so codes whose
     * name literally contains a key word (e.g. "şpris") always reach the LLM,
     * then ranked by trigram word similarity. Falls back to pure trigram when
     * the query has no usable tokens.
     *
     * @return array<int, object>
     */
    private function lexical(string $text, int $per, string $kindSql, array $kindBind): array
    {
        $tokens = $this->tokens($text);

        // Search name + everyday synonyms together.
        $haystack = "(name || ' ' || coalesce(synonyms, ''))";

        if (empty($tokens)) {
            return DB::select(
                "SELECT id, code, name, kind, word_similarity(?, {$haystack}) AS sim
                 FROM catalog
                 WHERE TRUE {$kindSql}
                 ORDER BY word_similarity(?, {$haystack}) DESC
                 LIMIT {$per}",
                array_merge([$text], $kindBind, [$text]),
            );
        }

        // Process tokens rarest-first: a code matched by a distinctive word
        // (e.g. "şpris", in 5 names) is far more relevant than one matched by a
        // common word (e.g. "rezin", in hundreds). Each token contributes its
        // best matches, so the rare-but-decisive code is not crowded out.
        $freq = [];
        foreach ($tokens as $token) {
            $like = '%'.$token.'%';
            $freq[$token] = (int) DB::table('catalog')
                ->where(fn ($q) => $q->where('name', 'ILIKE', $like)->orWhere('synonyms', 'ILIKE', $like))
                ->count();
        }
        $freq = array_filter($freq, fn ($c) => $c > 0);
        asort($freq);
        $ordered = array_keys($freq);

        $perToken = max(4, (int) ceil($per / max(1, count($ordered))));
        $list = [];
        $seen = [];

        foreach ($ordered as $token) {
            $like = '%'.$token.'%';
            $rows = DB::select(
                "SELECT id, code, name, kind, word_similarity(?, {$haystack}) AS sim
                 FROM catalog
                 WHERE (name ILIKE ? OR synonyms ILIKE ?) {$kindSql}
                 ORDER BY word_similarity(?, {$haystack}) DESC
                 LIMIT {$perToken}",
                array_merge([$text, $like, $like], $kindBind, [$text]),
            );
            foreach ($rows as $row) {
                if (! isset($seen[$row->id])) {
                    $seen[$row->id] = true;
                    $list[] = $row;
                }
            }
        }

        return array_slice($list, 0, $per);
    }

    /**
     * Drop measurement/spec tokens (anything containing a digit) before
     * embedding the query, so noise like "5ml 23G Х32" doesn't dominate the
     * semantic signal. Keeps the meaningful words.
     */
    private function normalize(string $text): string
    {
        $words = array_filter(
            preg_split('/\s+/u', trim($text)) ?: [],
            fn ($w) => $w !== '' && ! preg_match('/\d/u', $w),
        );
        $clean = trim(implode(' ', $words));

        return $clean !== '' ? $clean : trim($text);
    }

    /**
     * Significant alphabetic tokens (length >= 4) used to gate lexical search.
     *
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text)) ?: [];
        $words = array_filter($words, fn ($w) => mb_strlen($w) >= 4);

        return array_values(array_unique($words));
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
