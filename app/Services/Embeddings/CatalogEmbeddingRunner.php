<?php

namespace App\Services\Embeddings;

use Illuminate\Support\Facades\DB;

class CatalogEmbeddingRunner
{
    public function __construct(private readonly OllamaEmbedder $embedder) {}

    public function pendingCount(): int
    {
        return (int) DB::table('catalog')->whereNull('embedding')->count();
    }

    public function clear(): void
    {
        DB::statement('UPDATE catalog SET embedding = NULL, embedded_at = NULL');
    }

    /**
     * Embed the next batch of up to $size rows that still lack an embedding.
     * Resumable by design: each call simply picks the next NULL-embedding rows.
     *
     * @return int rows embedded in this call (0 means nothing left to do)
     */
    public function embedBatch(int $size): int
    {
        return $this->embedWhere(fn ($q) => $q->whereNull('embedding'), $size);
    }

    /** Rows still needing a refresh (never embedded, or embedded before $before). */
    public function staleCount(string $before): int
    {
        return (int) DB::table('catalog')
            ->where(fn ($q) => $q->whereNull('embedding')->orWhere('embedded_at', '<', $before))
            ->count();
    }

    /**
     * Re-embed a batch of rows in place (no NULL gap): picks rows not embedded
     * since $before and overwrites their vector. Lets a full re-embed run in the
     * background while the old vectors keep serving search until overwritten.
     */
    public function refreshBatch(string $before, int $size): int
    {
        return $this->embedWhere(
            fn ($q) => $q->where(fn ($w) => $w->whereNull('embedding')->orWhere('embedded_at', '<', $before)),
            $size,
        );
    }

    private function embedWhere(callable $scope, int $size): int
    {
        $query = DB::table('catalog');
        $scope($query);

        $rows = $query
            ->orderBy('id')
            ->limit(max(1, $size))
            ->get(['id', 'name']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $vectors = $this->embedder->embed($rows->map(fn ($r) => $this->embedText($r->name))->all());

        DB::transaction(function () use ($rows, $vectors) {
            foreach ($rows->values() as $i => $row) {
                DB::update(
                    'UPDATE catalog SET embedding = ?::vector, embedded_at = now() WHERE id = ?',
                    [OllamaEmbedder::toSqlVector($vectors[$i]), $row->id],
                );
            }
        });

        return $rows->count();
    }

    /**
     * The registry repeats the full HS path in every name. We embed the HEAD
     * segment (the category, e.g. "medical devices") plus the LEAF segment (the
     * specific term) — keeping category context while dropping the repeated
     * mid-path boilerplate. This improves recall for items whose meaning depends
     * on the category, not just the leaf word.
     */
    private function embedText(string $name): string
    {
        $segments = array_values(array_filter(array_map(
            'trim',
            preg_split('/–/u', $name) ?: [$name],
        )));

        if (count($segments) <= 1) {
            return $this->clip($segments[0] ?? trim($name), 16);
        }

        // Keep a short category head (first words) + the specific leaf. The full
        // head can be very long; clipping it keeps the signal while cutting the
        // token count (and embedding time) several-fold.
        $head = $this->clip($segments[0], 8);
        $leaf = $this->clip(end($segments), 16);

        return $head === $leaf ? $leaf : $head.' — '.$leaf;
    }

    private function clip(string $text, int $words): string
    {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];

        return implode(' ', array_slice($parts, 0, $words));
    }
}
