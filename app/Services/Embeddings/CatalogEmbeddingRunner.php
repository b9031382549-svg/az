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
        $rows = DB::table('catalog')
            ->whereNull('embedding')
            ->orderBy('id')
            ->limit(max(1, $size))
            ->get(['id', 'name']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $vectors = $this->embedder->embed($rows->map(fn ($r) => $this->leaf($r->name))->all());

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
     * The registry repeats the full HS path in every name; the discriminative
     * term is the last "–"-separated segment. Embedding that leaf is faster and
     * more specific than embedding the whole boilerplate.
     */
    private function leaf(string $name): string
    {
        $segments = array_values(array_filter(array_map(
            'trim',
            preg_split('/–/u', $name) ?: [$name],
        )));

        $leaf = end($segments);

        return is_string($leaf) && $leaf !== '' ? $leaf : trim($name);
    }
}
