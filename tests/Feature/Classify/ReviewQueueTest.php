<?php

namespace Tests\Feature\Classify;

use App\Livewire\ReviewQueue;
use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
    }

    private function seedCatalog(): void
    {
        CatalogCode::create(['code' => '8471300000', 'name' => 'noutbuk', 'kind' => 'good', 'chapter' => '84', 'position' => '8471', 'subposition' => '847130', 'is_active' => true]);
        CatalogCode::create(['code' => '8528720000', 'name' => 'televizor', 'kind' => 'good', 'chapter' => '85', 'position' => '8528', 'subposition' => '852872', 'is_active' => true]);
    }

    private function agreedItem(): ClassificationItem
    {
        $item = ClassificationItem::create([
            'batch' => 'b', 'source_text' => 'noutbuk', 'source_hash' => bin2hex(random_bytes(32)),
            'kind' => 'good', 'resolution' => 'agreed', 'final_code' => '8471300000',
            'final_catalog_id' => CatalogCode::where('code', '8471300000')->value('id'),
        ]);
        $item->results()->create([
            'mechanism' => 'vector', 'matched_code' => '8471300000', 'status' => 'auto_confirmed',
            'candidates' => [['code' => '8471300000'], ['code' => '8528720000']], 'kind' => 'good',
        ]);

        return $item;
    }

    private function actingComponent()
    {
        return Livewire::actingAs(User::factory()->create())->test(ReviewQueue::class);
    }

    public function test_confirm_sets_final_and_confirmed_by(): void
    {
        $item = $this->agreedItem();
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(ReviewQueue::class)->call('confirmWith', $item->id, '8471300000');

        $item->refresh();
        $this->assertSame('confirmed', $item->resolution);
        $this->assertSame('8471300000', $item->final_code);
        $this->assertSame($user->id, $item->confirmed_by);
        $this->assertNotNull($item->confirmed_at);
    }

    public function test_correction_switches_to_another_candidate(): void
    {
        $item = $this->agreedItem();

        $this->actingComponent()->call('confirmWith', $item->id, '8528720000');

        $item->refresh();
        $this->assertSame('8528720000', $item->final_code);
        $this->assertSame(CatalogCode::where('code', '8528720000')->value('id'), $item->final_catalog_id);
        $this->assertSame('confirmed', $item->resolution);
    }

    public function test_confirm_rejects_a_code_no_mechanism_considered(): void
    {
        CatalogCode::create(['code' => '9999999999', 'name' => 'x', 'kind' => 'good', 'is_active' => true]);
        $item = $this->agreedItem();

        $this->actingComponent()->call('confirmWith', $item->id, '9999999999');

        $this->assertSame('agreed', $item->fresh()->resolution);
        $this->assertSame('8471300000', $item->fresh()->final_code);
    }

    public function test_conflict_can_be_confirmed_with_a_mechanisms_own_pick(): void
    {
        // vector picked 8471 (only that in its candidates); broker picked 8528 with
        // no candidate list — 8528 must still be confirmable (allowedCodes includes
        // each result's matched_code).
        $item = ClassificationItem::create([
            'batch' => 'b', 'source_text' => 'device', 'source_hash' => bin2hex(random_bytes(32)),
            'resolution' => 'conflict',
        ]);
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '8471300000', 'status' => 'auto_confirmed', 'candidates' => [['code' => '8471300000']], 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => '8528720000', 'status' => 'auto_confirmed', 'candidates' => [], 'kind' => 'good']);

        $this->actingComponent()->call('confirmWith', $item->id, '8528720000');

        $this->assertSame('8528720000', $item->fresh()->final_code);
        $this->assertSame('confirmed', $item->fresh()->resolution);
    }

    public function test_reject_marks_item_rejected(): void
    {
        $item = $this->agreedItem();

        $this->actingComponent()->call('reject', $item->id);

        $this->assertSame('rejected', $item->fresh()->resolution);
    }

    public function test_queue_renders(): void
    {
        $this->agreedItem();

        $this->actingComponent()->assertOk();
    }
}
