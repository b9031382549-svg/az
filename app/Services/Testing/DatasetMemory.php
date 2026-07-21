<?php

namespace App\Services\Testing;

use App\Models\AnswerCache;
use App\Models\TestDataset;
use App\Models\TestRun;
use Illuminate\Support\Collection;

/**
 * Manages a dataset's OWN answer-cache memory (rows scoped by test_dataset_id).
 * Production memory (scope 0) is never touched here. Two ways to populate it, each a
 * different "memory-addition algorithm" to measure:
 *  - seedFromLabels: the dataset's correct answers → the PERFECT-MEMORY upper bound
 *    (leakage: memory then scores ~100% on exact-name rows — a ceiling, not a real gain).
 *  - seedFromRun: a completed run's produced answers → the flywheel replay (memory built
 *    from what the pipeline actually output, right or wrong).
 */
class DatasetMemory
{
    public function count(TestDataset $dataset): int
    {
        return AnswerCache::where('test_dataset_id', $dataset->id)->count();
    }

    public function seedFromLabels(TestDataset $dataset): int
    {
        $rows = $dataset->scorableRows()->get()->map(fn ($r) => $this->row(
            $dataset->id, $r->source_text, $r->expected_heading, (bool) $r->expected_is_service, 'dataset-labels',
        ));

        return $this->upsert($rows);
    }

    public function seedFromRun(TestRun $run): int
    {
        $rows = $run->items()->whereNotNull('final_code')->get()->map(fn ($i) => $this->row(
            (int) $run->test_dataset_id,
            (string) $i->source_text,
            mb_substr((string) $i->final_code, 0, 4),
            $i->kind === 'service',
            'run:'.$run->id,
        ));

        return $this->upsert($rows);
    }

    public function clear(TestDataset $dataset): int
    {
        return AnswerCache::where('test_dataset_id', $dataset->id)->delete();
    }

    /** @return array<string, mixed> */
    private function row(int $datasetId, string $name, ?string $heading, bool $isService, string $source): array
    {
        return [
            'test_dataset_id' => $datasetId,
            'source' => $source,
            'name' => $name,
            'name_key' => AnswerCache::keyFor($name),
            'heading' => $isService ? null : $heading,
            'is_service' => $isService,
            'tier' => $source,
            'meta' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function upsert($rows): int
    {
        $rows = $rows->keyBy('name_key')->values()->all(); // one row per (scope, name)
        foreach (array_chunk($rows, 500) as $chunk) {
            AnswerCache::upsert($chunk, ['test_dataset_id', 'name_key'], ['source', 'name', 'heading', 'is_service', 'tier', 'updated_at']);
        }

        return count($rows);
    }
}
