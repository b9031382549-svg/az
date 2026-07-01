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
     * Accepts one query or several (e.g. the LLM-normalized canonical name plus
     * the noise-stripped raw text). Each query contributes a semantic and a
     * lexical ranked list; all are fused with RRF, so a clean head-noun query
     * surfaces the right code even when the raw text's brand/barcode/flavour
     * noise pulls a different sense.
     *
     * @param  string|array<int, string>  $queries
     * @return array<int, object{id:int, code:string, name:string, kind:string, score:float}>
     */
    public function candidates(string|array $queries, int $limit = 24, ?string $kind = null): array
    {
        $queries = array_values(array_filter(
            array_map('trim', is_array($queries) ? $queries : [$queries]),
            fn ($q) => $q !== '',
        ));
        if (empty($queries)) {
            return [];
        }

        $per = max($limit, 30);
        $kindSql = $kind ? 'AND kind = ?' : '';
        $kindBind = $kind ? [$kind] : [];

        // The first query is primary (canonical) — its vector backs the
        // auto-confirm semantic-similarity gate.
        $lists = [];
        $primaryVector = null;
        foreach ($queries as $i => $q) {
            $vector = OllamaEmbedder::toSqlVector($this->embedder->embedOne($this->normalize($q)));
            if ($i === 0) {
                $primaryVector = $vector;
            }
            $lists[] = $this->semantic($vector, $per, $kindSql, $kindBind);
            $lists[] = $this->lexical($q, $per, $kindSql, $kindBind);
        }

        $fused = $this->fuse($lists, $limit);
        $this->attachSemanticSim($fused, $primaryVector);

        return $fused;
    }

    /**
     * Cosine similarity of a free-text query to ONE catalog code's embedding, or
     * null when unavailable (no embedding, or not Postgres). Lets a non-retrieval
     * mechanism (the broker) gate its pick on the same semantic backing as vector.
     */
    public function semanticSimilarity(string $query, string $code): ?float
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return null; // pgvector-only; sqlite tests have no embeddings
        }

        $q = $this->normalize($query);
        if ($q === '') {
            return null;
        }

        $vector = OllamaEmbedder::toSqlVector($this->embedder->embedOne($q));
        $row = DB::selectOne(
            'SELECT 1 - (embedding <=> ?::vector) AS sim
             FROM catalog WHERE code = ? AND embedding IS NOT NULL',
            [$vector, $code],
        );

        return $row !== null ? round((float) $row->sim, 4) : null;
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
     * Reciprocal Rank Fusion over any number of ranked lists.
     *
     * @param  array<int, array<int, object>>  $lists
     * @return array<int, object>
     */
    private function fuse(array $lists, int $limit): array
    {
        $k = 60; // RRF damping
        $scores = [];
        $rows = [];

        foreach ($lists as $list) {
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
