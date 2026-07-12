<?php

namespace App\Services\Embeddings;

use Illuminate\Support\Facades\DB;

/**
 * Embeds the precedent corpus (bge-m3). We embed the `az` name as-is — it is
 * already a short canonical product name (the same shape as a catalog leaf), so
 * precedent and catalog vectors live in a comparable space for RRF fusion.
 * Resumable by design, mirroring CatalogEmbeddingRunner.
 */
class PrecedentEmbeddingRunner
{
    public function __construct(private readonly OllamaEmbedder $embedder) {}

    public function pendingCount(): int
    {
        return (int) DB::table('precedents')->whereNull('embedding')->count();
    }

    public function clear(): void
    {
        DB::statement('UPDATE precedents SET embedding = NULL, embedded_at = NULL');
    }

    /**
     * Embed the next batch of up to $size rows that still lack an embedding.
     * Resumable: each call simply picks the next NULL-embedding rows.
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
        return (int) DB::table('precedents')
            ->where(fn ($q) => $q->whereNull('embedding')->orWhere('embedded_at', '<', $before))
            ->count();
    }

    /**
     * Re-embed a batch in place (no NULL gap): picks rows not embedded since
     * $before and overwrites their vector, so old vectors keep serving search
     * until overwritten.
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
        $query = DB::table('precedents');
        $scope($query);

        $rows = $query
            ->orderBy('id')
            ->limit(max(1, $size))
            ->get(['id', 'az']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $vectors = $this->embedder->embed($rows->map(fn ($r) => trim((string) $r->az))->all());

        DB::transaction(function () use ($rows, $vectors) {
            foreach ($rows->values() as $i => $row) {
                DB::update(
                    'UPDATE precedents SET embedding = ?::vector, embedded_at = now() WHERE id = ?',
                    [OllamaEmbedder::toSqlVector($vectors[$i]), $row->id],
                );
            }
        });

        return $rows->count();
    }
}
