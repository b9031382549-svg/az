<?php

namespace Tests\Feature\Testing;

use App\Livewire\ReviewQueue;
use App\Models\ClassificationItem;
use App\Models\TestDataset;
use App\Models\TestRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TestIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression for the reviewer's blocker: with NO global scope, the search
     * resolver's static whereKey()->update() flip still affects a test row (a global
     * scope would append `AND test_run_id IS NULL` and match 0 rows, silently).
     */
    public function test_static_update_flips_a_test_row(): void
    {
        $run = $this->makeRun();
        $item = $this->makeItem($run, 'conflict');

        $affected = ClassificationItem::whereKey($item->id)
            ->whereIn('resolution', ['conflict', 'review'])
            ->update(['resolution' => 'ai_resolved', 'final_code' => '0901']);

        $this->assertSame(1, $affected);
        $this->assertSame('ai_resolved', $item->fresh()->resolution);
    }

    public function test_review_queue_excludes_test_rows(): void
    {
        $this->actingAs(User::factory()->create());

        ClassificationItem::create([
            'batch' => 'b', 'source_text' => 'prodwidget',
            'source_hash' => bin2hex(random_bytes(32)), 'resolution' => 'conflict',
        ]);
        $run = $this->makeRun();
        $this->makeItem($run, 'conflict', 'testwidget');

        Livewire::test(ReviewQueue::class)
            ->set('filter', 'open')
            ->assertSee('prodwidget')
            ->assertDontSee('testwidget');
    }

    public function test_deleting_a_run_cascades_items_and_results(): void
    {
        $run = $this->makeRun();
        $item = $this->makeItem($run, 'agreed');
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '0901', 'status' => 'no_match']);

        $run->delete();

        $this->assertDatabaseMissing('classification_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('classification_results', ['classification_item_id' => $item->id]);
        // Nothing left orphaned with a null test_run_id (which would re-surface in prod).
        $this->assertSame(0, ClassificationItem::count());
    }

    private function makeRun(): TestRun
    {
        $mech = ['enabled' => ['vector', 'broker', 'direct'], 'shadow' => [], 'cache' => false, 'search' => false];
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => $mech]);
        $run = TestRun::create([
            'test_dataset_id' => $dataset->id, 'description' => 'r', 'batch' => 'tmp',
            'mechanisms' => $mech, 'config' => [], 'status' => 'running', 'total' => 0,
        ]);
        $run->update(['batch' => TestRun::batchKey($run->id)]);

        return $run;
    }

    private function makeItem(TestRun $run, string $resolution, string $text = 'x'): ClassificationItem
    {
        return ClassificationItem::create([
            'batch' => $run->batch,
            'test_run_id' => $run->id,
            'source_text' => $text,
            'source_hash' => bin2hex(random_bytes(32)),
            'resolution' => $resolution,
        ]);
    }
}
