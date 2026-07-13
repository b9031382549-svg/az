<?php

namespace App\Services\Classify;

use App\Services\Embeddings\OllamaEmbedder;
use App\Support\AzFold;
use Illuminate\Support\Facades\DB;

class CatalogRetriever
{
    private readonly bool $precedentsEnabled;

    private readonly int $precedentTopK;

    private readonly int $precedentPerHeading;

    private readonly bool $headingFusion;

    private readonly int $headingCodes;

    public function __construct(private readonly OllamaEmbedder $embedder)
    {
        $this->precedentsEnabled = (bool) config('classify.precedents.enabled', false);
        $this->precedentTopK = max(1, (int) config('classify.precedents.top_k', 40));
        $this->precedentPerHeading = max(1, (int) config('classify.precedents.per_heading', 4));
        $this->headingFusion = (bool) config('classify.retrieval.heading_fusion', false);
        $this->headingCodes = max(1, (int) config('classify.retrieval.heading_codes', 2));
    }

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
        $headingLists = [];
        $primaryVector = null;
        foreach ($queries as $i => $q) {
            $vector = OllamaEmbedder::toSqlVector($this->embedder->embedOne($this->normalize($q)));
            if ($i === 0) {
                $primaryVector = $vector;
            }
            $sem = $this->semantic($vector, $per, $kindSql, $kindBind);
            $lex = $this->lexical($q, $per, $kindSql, $kindBind);
            $lists[] = $sem;
            $lists[] = $lex;

            if ($this->headingFusion) {
                // Sources vote at the 4-digit heading level; precedents vote their
                // heading directly (no code expansion).
                $headingLists[] = $this->headingsOf($sem);
                $headingLists[] = $this->headingsOf($lex);
                if ($this->precedentsEnabled) {
                    $headingLists[] = $this->precedentHeadings($vector);
                }
            } elseif ($this->precedentsEnabled) {
                $lists[] = $this->precedentSemantic($vector, $per, $kindSql, $kindBind);
            }
        }

        $fused = $this->headingFusion
            ? $this->fuseByHeading($lists, $headingLists, (string) $primaryVector, $limit, $kindSql, $kindBind)
            : $this->fuse($lists, $limit);
        $this->attachSemanticSim($fused, $primaryVector);

        return $fused;
    }

    /**
     * Distinct 4-digit headings of a ranked code list, in first-occurrence order —
     * the source's ranked "vote" over headings.
     *
     * @param  array<int, object>  $rows
     * @return array<int, string>
     */
    private function headingsOf(array $rows): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $h = substr((string) $r->code, 0, 4);
            if ($h === '' || isset($seen[$h])) {
                continue;
            }
            $seen[$h] = true;
            $out[] = $h;
        }

        return $out;
    }

    /**
     * The nearest precedents' 4-digit headings, rank-decayed and aggregated — a
     * heading-level vote (no HS6→code expansion). @return array<int, string>
     */
    private function precedentHeadings(string $vector): array
    {
        $hits = DB::select(
            "SELECT hs6 FROM precedents WHERE embedding IS NOT NULL
             ORDER BY embedding <=> ?::vector LIMIT {$this->precedentTopK}",
            [$vector],
        );
        if (empty($hits)) {
            return [];
        }
        $k = 60;
        $score = [];
        foreach (array_values($hits) as $rank => $h) {
            $head = substr((string) $h->hs6, 0, 4);
            $score[$head] = ($score[$head] ?? 0) + 1 / ($k + $rank + 1);
        }
        arsort($score);

        return array_keys($score);
    }

    /** Reciprocal Rank Fusion over ranked key lists → keys, best first. */
    private function rrfKeys(array $lists, int $k = 60): array
    {
        $score = [];
        foreach ($lists as $keys) {
            $seen = [];
            $rank = 0;
            foreach ($keys as $key) {
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $score[$key] = ($score[$key] ?? 0) + 1 / ($k + $rank + 1);
                $rank++;
            }
        }
        arsort($score);

        return array_keys($score);
    }

    /**
     * Heading-first shortlist assembly. Rank the 4-digit headings by RRF over the
     * heading votes; then emit catalog codes heading by heading — a heading's own
     * semantic/lexical candidates (best code-score first), or, for a heading only a
     * precedent voted for, its catalog codes nearest the query.
     *
     * @param  array<int, array<int, object>>  $codeLists  semantic+lexical rows (for within-heading code order)
     * @param  array<int, array<int, string>>  $headingLists  ranked heading votes per source
     * @return array<int, object>
     */
    private function fuseByHeading(array $codeLists, array $headingLists, string $vector, int $limit, string $kindSql, array $kindBind): array
    {
        $rankedHeadings = $this->rrfKeys($headingLists);
        if (empty($rankedHeadings)) {
            return $this->fuse($codeLists, $limit);
        }

        // code-level RRF scores of the semantic/lexical pool, for ordering codes within a heading
        $k = 60;
        $cScore = [];
        $rows = [];
        foreach ($codeLists as $list) {
            foreach (array_values($list) as $rank => $row) {
                $cScore[$row->id] = ($cScore[$row->id] ?? 0) + 1 / ($k + $rank + 1);
                $rows[$row->id] = $row;
            }
        }
        $byHeading = [];
        foreach ($rows as $id => $row) {
            $byHeading[substr((string) $row->code, 0, 4)][] = $id;
        }

        $out = [];
        $pos = 0;
        foreach ($rankedHeadings as $h) {
            if (isset($byHeading[$h])) {
                $ids = $byHeading[$h];
                usort($ids, fn ($a, $b) => $cScore[$b] <=> $cScore[$a]);
                $ids = array_slice($ids, 0, $this->headingCodes); // cap so the shortlist covers many headings
                $emit = array_map(fn ($id) => $rows[$id], $ids);
            } else {
                // heading only a precedent voted for — pull its nearest catalog codes
                $emit = DB::select(
                    "SELECT id, code, name, kind FROM catalog
                     WHERE position = ? AND embedding IS NOT NULL {$kindSql}
                     ORDER BY embedding <=> ?::vector LIMIT {$this->precedentPerHeading}",
                    array_merge([$h], $kindBind, [$vector]),
                );
            }
            foreach ($emit as $row) {
                $row->score = round(1 / (1 + $pos), 5);
                $out[] = $row;
                $pos++;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
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
     * Precedent-backed candidate generation — a third retrieval source. Finds the
     * nearest real-customs precedents to the query, aggregates their evidence by
     * HS6 heading (rank-decayed, so repeated and higher-ranked precedents push a
     * heading up), then maps the winning headings to catalog candidate codes. The
     * fan-out per heading is capped so one heading can't crowd out the rest, and
     * the whole list is bounded to $per for balanced RRF weight. Grounded in how
     * real products were actually classified, not in the catalog's legal wording.
     *
     * @return array<int, object>
     */
    private function precedentSemantic(string $vector, int $per, string $kindSql, array $kindBind): array
    {
        // Nearest precedents by cosine (HNSW). Over-fetch so several precedents can
        // vote per heading.
        $hits = DB::select(
            "SELECT hs6
             FROM precedents
             WHERE embedding IS NOT NULL
             ORDER BY embedding <=> ?::vector
             LIMIT {$this->precedentTopK}",
            [$vector],
        );
        if (empty($hits)) {
            return [];
        }

        // Rank-decayed vote per heading: a heading backed by several near
        // precedents (or one very near) outranks a heading seen once, far down.
        $k = 60;
        $headingScore = [];
        foreach (array_values($hits) as $rank => $hit) {
            $headingScore[$hit->hs6] = ($headingScore[$hit->hs6] ?? 0) + 1 / ($k + $rank + 1);
        }
        arsort($headingScore);

        // Expand each heading (best first) into a few catalog codes, keeping the
        // list heading-ranked and bounded. A heading with no catalog rows (or none
        // of the requested kind) simply contributes nothing.
        $out = [];
        foreach (array_keys($headingScore) as $hs6) {
            $rows = DB::select(
                "SELECT id, code, name, kind
                 FROM catalog
                 WHERE subposition = ? {$kindSql}
                 ORDER BY code
                 LIMIT {$this->precedentPerHeading}",
                array_merge([$hs6], $kindBind),
            );
            foreach ($rows as $row) {
                $out[] = $row;
                if (count($out) >= $per) {
                    return $out;
                }
            }
        }

        return $out;
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
        // Diacritic-fold the query so a stripped invoice term ("kisi koynek")
        // matches the catalog's correct spelling. Matching runs against the folded
        // search_text column (name + synonyms, folded); the embeddings/vector leg
        // are untouched. Fall back to the raw text where search_text is not built.
        $folded = AzFold::fold($text);
        $tokens = $this->tokens($folded);

        // Bare column (not an expression) so the trigram GIN index on search_text
        // is used. It is populated for every row by catalog:build-search-text on
        // deploy; the brief NULL window (migrate → build) just falls back to the
        // vector leg for lexical matches.
        $haystack = 'search_text';

        if (empty($tokens)) {
            return DB::select(
                "SELECT id, code, name, kind, word_similarity(?, {$haystack}) AS sim
                 FROM catalog
                 WHERE TRUE {$kindSql}
                 ORDER BY word_similarity(?, {$haystack}) DESC
                 LIMIT {$per}",
                array_merge([$folded], $kindBind, [$folded]),
            );
        }

        // Process tokens rarest-first: a code matched by a distinctive word
        // (e.g. "spris", in 5 names) is far more relevant than one matched by a
        // common word (e.g. "rezin", in hundreds). Each token contributes its
        // best matches, so the rare-but-decisive code is not crowded out.
        $freq = [];
        foreach ($tokens as $token) {
            $like = '%'.$token.'%';
            $freq[$token] = (int) DB::selectOne(
                "SELECT count(*) AS c FROM catalog WHERE {$haystack} ILIKE ?",
                [$like],
            )->c;
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
                 WHERE {$haystack} ILIKE ? {$kindSql}
                 ORDER BY word_similarity(?, {$haystack}) DESC
                 LIMIT {$perToken}",
                array_merge([$folded, $like], $kindBind, [$folded]),
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
