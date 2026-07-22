<?php

namespace Tests\Feature\Testing;

use App\Models\ClassificationItem;
use App\Models\TestDataset;
use App\Models\TestRun;
use App\Services\Testing\TestRunFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-item reconciler used by the mechanism jobs. It reuses Consensus::resolve()
 * but takes the authoritative set + search toggle from the RUN (not global config).
 */
class TestRunFinalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_waits_until_every_authoritative_mechanism_reported(): void
    {
        [, $item] = $this->runItem(['vector', 'broker', 'direct']);
        $this->storeResult($item, 'vector', '0901000000');

        $this->assertFalse(app(TestRunFinalizer::class)->finalize($item));
        $this->assertSame('pending', $item->fresh()->resolution);
    }

    public function test_abstentions_count_so_a_lone_vote_is_not_a_false_agreement(): void
    {
        [, $item] = $this->runItem(['vector', 'broker', 'direct']);
        $this->storeResult($item, 'vector', '0901000000');
        $this->storeResult($item, 'broker', null, 'error');
        $this->storeResult($item, 'direct', null, 'error');

        $this->assertFalse(app(TestRunFinalizer::class)->finalize($item)); // search off
        $this->assertSame('conflict', $item->fresh()->resolution); // 1 of 3 coded is not a majority
    }

    public function test_two_of_three_agree_resolves_at_the_heading(): void
    {
        [, $item] = $this->runItem(['vector', 'broker', 'direct']);
        $this->storeResult($item, 'vector', '0901000000');
        $this->storeResult($item, 'broker', '0901110000');
        $this->storeResult($item, 'direct', '0902000000');

        $this->assertFalse(app(TestRunFinalizer::class)->finalize($item));
        $this->assertSame('agreed', $item->fresh()->resolution);
        $this->assertSame('0901', $item->fresh()->final_code);
    }

    public function test_conflict_with_search_claims_exactly_once(): void
    {
        [, $item] = $this->runItem(['vector', 'broker', 'direct'], search: true);
        $this->storeResult($item, 'vector', '0901000000');
        $this->storeResult($item, 'broker', '0902000000');
        $this->storeResult($item, 'direct', '0903000000');

        $finalizer = app(TestRunFinalizer::class);
        $this->assertTrue($finalizer->finalize($item));   // conflict + search → dispatch search, claim won
        $this->assertNotNull($item->fresh()->search_resolved_at);
        $this->assertFalse($finalizer->finalize($item));  // second call: already claimed → no re-dispatch
    }

    /** @return array{0: TestRun, 1: ClassificationItem} */
    private function runItem(array $enabled, bool $search = false): array
    {
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => []]);
        $run = TestRun::create([
            'test_dataset_id' => $dataset->id, 'description' => 'r',
            'mechanisms' => ['enabled' => $enabled, 'shadow' => [], 'cache' => false, 'search' => $search],
            'config' => [], 'status' => 'running', 'total' => 1,
        ]);
        $run->update(['batch' => TestRun::batchKey($run->id)]);
        $item = ClassificationItem::create([
            'batch' => $run->batch, 'test_run_id' => $run->id, 'source_text' => 'x',
            'source_hash' => bin2hex(random_bytes(32)), 'resolution' => 'pending',
        ]);

        return [$run, $item];
    }

    private function storeResult(ClassificationItem $item, string $mech, ?string $code, string $status = 'auto_confirmed'): void
    {
        $item->results()->create([
            'mechanism' => $mech, 'matched_code' => $code,
            'kind' => $code !== null ? 'good' : null, 'status' => $status,
        ]);
    }
}
