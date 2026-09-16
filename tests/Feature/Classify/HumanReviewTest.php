<?php

namespace Tests\Feature\Classify;

use App\Livewire\HumanReview;
use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Models\TestDataset;
use App\Models\TestRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HumanReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A real heading so a 4-digit confirm is accepted (position lookup in the trait).
        CatalogCode::create(['code' => '1104300000', 'name' => 'oat', 'kind' => 'good', 'position' => '1104', 'is_active' => true]);
    }

    private function open(string $resolution, string $text, string $batch = 'b'): ClassificationItem
    {
        $item = ClassificationItem::create([
            'batch' => $batch, 'source_text' => $text, 'source_hash' => bin2hex(random_bytes(16)),
            'resolution' => $resolution,
        ]);

        // A TERMINAL conflict (the web-search resolver ran and could not settle it) carries a
        // 'search' trace — without it a conflict reads as still in-flight ('resolving') and is
        // deliberately kept OUT of the human queue.
        if ($resolution === 'conflict') {
            $item->results()->create(['mechanism' => 'search', 'matched_code' => null, 'status' => 'needs_review']);
        }

        return $item;
    }

    private function acting()
    {
        return Livewire::actingAs(User::factory()->create())->test(HumanReview::class);
    }

    public function test_queue_holds_only_items_that_need_a_human(): void
    {
        $conflict = $this->open('conflict', 'diverged item');
        $noMatch = $this->open('no_match', 'unknown item');
        $agreed = $this->open('agreed', 'auto item');           // resolved — not a human's job
        $agreed->update(['final_code' => '1104']);

        // A test-run row must never leak into the production human queue.
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => ['enabled' => ['vector']]]);
        $run = TestRun::create(['test_dataset_id' => $dataset->id, 'description' => 'r', 'batch' => 'tmp', 'mechanisms' => [], 'config' => [], 'status' => 'running', 'total' => 0]);
        ClassificationItem::create(['batch' => 'tr', 'test_run_id' => $run->id, 'source_text' => 'test item', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'conflict']);

        $ids = $this->acting()->viewData('queue')->pluck('id');

        $this->assertTrue($ids->contains($conflict->id));
        $this->assertTrue($ids->contains($noMatch->id));
        $this->assertFalse($ids->contains($agreed->id));
        $this->assertCount(2, $ids);
    }

    public function test_confirm_sets_the_code_and_drops_it_from_the_queue(): void
    {
        $item = $this->open('no_match', 'oat flakes 1kg');

        $c = $this->acting()->call('selectItem', $item->id)->call('confirm', '1104');

        $item->refresh();
        $this->assertSame('confirmed', $item->resolution);
        $this->assertSame('1104', $item->final_code);
        $this->assertFalse($c->viewData('queue')->pluck('id')->contains($item->id));
    }

    public function test_skip_leaves_the_item_untouched(): void
    {
        $a = $this->open('no_match', 'first');
        $b = $this->open('no_match', 'second');

        $c = $this->acting()->call('selectItem', $a->id)->call('skip');

        // Nothing written…
        $this->assertSame('no_match', $a->fresh()->resolution);
        // …and the selection advanced to the next item.
        $this->assertSame($b->id, $c->get('selected'));
    }

    public function test_reject_marks_the_item_rejected(): void
    {
        $item = $this->open('conflict', 'nope');

        $this->acting()->call('selectItem', $item->id)->call('rejectItem');

        $this->assertSame('rejected', $item->fresh()->resolution);
    }

    public function test_page_renders_with_tiles(): void
    {
        $this->open('no_match', 'x');

        $this->acting()->assertOk()
            ->assertSee('Waiting on a human')
            ->assertSee('Processed today');
    }
}
