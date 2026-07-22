<?php

namespace Tests\Feature\Testing;

use App\Models\ClassificationItem;
use App\Models\TestDataset;
use App\Models\TestDatasetRow;
use App\Models\TestRun;
use App\Services\Testing\RunScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scorer is dispatched from several places (the batch's finally, a hard-fail
 * re-trigger); its settle-guard must make a premature call a no-op so the persisted
 * score always reflects the fully-classified run.
 */
class RunScorerGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_finalize_is_a_noop_until_every_item_settles(): void
    {
        [$run, $rows] = $this->run2();
        $this->item($run, $rows[0], 'agreed', '0901');
        $pending = $this->item($run, $rows[1], 'pending');

        app(RunScorer::class)->finalize($run);
        $this->assertSame('running', $run->fresh()->status); // one item still pending → no-op
        $this->assertNull($run->fresh()->accuracy);

        $pending->update(['resolution' => 'no_match']);
        app(RunScorer::class)->finalize($run);
        $this->assertSame('done', $run->fresh()->status);    // all settled → scored
        $this->assertNotNull($run->fresh()->accuracy);
    }

    public function test_finalize_waits_for_a_conflict_still_mid_search(): void
    {
        [$run, $rows] = $this->run2();
        $this->item($run, $rows[0], 'agreed', '0901');
        // conflict that claimed a search but has no 'search' row yet → mid-search
        $c = $this->item($run, $rows[1], 'conflict');
        $c->update(['search_resolved_at' => now()]);

        app(RunScorer::class)->finalize($run);
        $this->assertSame('running', $run->fresh()->status);

        $c->results()->create(['mechanism' => 'search', 'matched_code' => '0402', 'kind' => 'good', 'status' => 'auto_confirmed']);
        app(RunScorer::class)->finalize($run);
        $this->assertSame('done', $run->fresh()->status);
    }

    /** @return array{0: TestRun, 1: array<int, TestDatasetRow>} */
    private function run2(): array
    {
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => []]);
        $rows = [
            $dataset->rows()->create(['source_text' => 'a', 'expected_heading' => '0901', 'expected_is_service' => false]),
            $dataset->rows()->create(['source_text' => 'b', 'expected_heading' => '0402', 'expected_is_service' => false]),
        ];
        $run = TestRun::create([
            'test_dataset_id' => $dataset->id, 'description' => 'r',
            'mechanisms' => ['enabled' => ['vector'], 'shadow' => [], 'cache' => false, 'search' => true],
            'config' => [], 'status' => 'running', 'total' => 2,
        ]);
        $run->update(['batch' => TestRun::batchKey($run->id)]);

        return [$run, $rows];
    }

    private function item(TestRun $run, $row, string $resolution, ?string $code = null): ClassificationItem
    {
        return ClassificationItem::create([
            'batch' => $run->batch, 'test_run_id' => $run->id, 'test_dataset_row_id' => $row->id,
            'source_text' => $row->source_text, 'source_hash' => bin2hex(random_bytes(32)),
            'resolution' => $resolution, 'final_code' => $code, 'kind' => $code ? 'good' : null,
        ]);
    }
}
