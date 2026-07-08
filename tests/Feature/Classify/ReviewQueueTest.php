<?php

namespace Tests\Feature\Classify;

use App\Livewire\ReviewQueue;
use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Models\GoldLabel;
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

    public function test_non_uuid_batch_is_listed_without_crashing(): void
    {
        // A seed/CLI batch key ("gold-ivan") is not a UUID; import_batches.key is a UUID
        // column, so the label lookup must skip it rather than throw (prod 22P02).
        ClassificationItem::create(['batch' => 'gold-ivan', 'source_text' => 'x', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '8471300000']);

        $c = $this->actingComponent()->assertOk();

        $this->assertContains('gold-ivan', collect($c->viewData('batches'))->pluck('key')->all());
    }

    public function test_uploads_table_lists_batches_and_selecting_one_filters_the_items(): void
    {
        ClassificationItem::create(['batch' => 'up-alpha', 'source_text' => 'alpha item', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '8471']);
        ClassificationItem::create(['batch' => 'up-alpha', 'source_text' => 'alpha 2', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'conflict']);
        ClassificationItem::create(['batch' => 'up-beta', 'source_text' => 'beta item', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'confirmed', 'final_code' => '1104']);

        $c = $this->actingComponent()->call('setFilter', 'all')->assertOk()
            ->assertSee('up-alpha')->assertSee('up-beta')  // both uploads listed in the table
            ->assertSee('resolved');                        // the result bar label

        // A batch carries its resolution breakdown for the result bar.
        $alpha = collect($c->viewData('uploads'))->firstWhere('key', 'up-alpha');
        $this->assertSame(2, $alpha->total);
        $this->assertSame(1, $alpha->resolved);
        $this->assertSame(1, $alpha->conflict);
        $this->assertSame(50, $alpha->done);

        // Selecting an upload scopes the item list to that batch.
        $c->call('selectBatch', 'up-beta');
        $this->assertSame('up-beta', $c->get('batch'));
        $this->assertSame(1, $c->viewData('items')->total());
    }

    public function test_review_card_shows_the_gold_reference_hint(): void
    {
        GoldLabel::create(['source' => 'fedor', 'name' => 'diamar', 'name_key' => GoldLabel::keyFor('diamar'), 'heading' => '3304', 'is_service' => false, 'tier' => 'claude', 'category' => 'cosmetic guess', 'meta' => ['crosscheck' => 'disagree', 'gpt_heading' => '2106']]);
        ClassificationItem::create(['batch' => 'b', 'source_text' => 'diamar', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'conflict']);

        $this->actingComponent()
            ->call('setFilter', 'all')
            ->assertSee('📋')
            ->assertSee('3304')
            ->assertSee('disputed');
    }

    public function test_agreed_and_ai_resolved_merge_into_found(): void
    {
        $this->agreedItem(); // resolution = agreed
        ClassificationItem::create(['batch' => 'b', 'source_text' => 'x1', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'ai_resolved', 'final_code' => '8471', 'kind' => 'good']);

        $c = $this->actingComponent();
        $counts = $c->viewData('counts');

        $this->assertSame(2, (int) ($counts['found'] ?? 0));           // agreed + ai_resolved merged
        $this->assertArrayNotHasKey('agreed', $counts->toArray());     // individual keys folded away
        $this->assertArrayNotHasKey('ai_resolved', $counts->toArray());

        $c->call('setFilter', 'found');
        $this->assertSame(2, $c->viewData('items')->total());          // the Found filter returns both
    }

    public function test_convergence_widget_does_not_reopen_a_resolved_item_as_conflict(): void
    {
        // A resolved item (has a final code) whose raw mechanisms diverge must NOT be
        // counted as a conflict by the convergence widget (the old 42-vs-3 bug).
        $item = ClassificationItem::create(['batch' => 'b', 'source_text' => 'x', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'ai_resolved', 'final_code' => '8471']);
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '8528720000', 'status' => 'auto_confirmed', 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => '9018390000', 'status' => 'auto_confirmed', 'kind' => 'good']);

        $c = $this->actingComponent();

        $this->assertSame(0, (int) ($c->viewData('counts')['conflict'] ?? 0));
        $this->assertSame(4, $c->viewData('agreement')['n']);        // always the 4-digit heading
        $this->assertSame(0, $c->viewData('agreement')['diverge']);
        $this->assertSame(1, $c->viewData('agreement')['converge']);
    }

    public function test_convergence_widget_keeps_a_cross_heading_conflict_divergent(): void
    {
        // Different headings (8471 vs 8528) → a genuine conflict, counted as diverge.
        $item = ClassificationItem::create([
            'batch' => 'b', 'source_text' => 'device', 'source_hash' => bin2hex(random_bytes(32)),
            'resolution' => 'conflict',
        ]);
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '8471300000', 'status' => 'auto_confirmed', 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => '8528720000', 'status' => 'auto_confirmed', 'kind' => 'good']);

        $c = $this->actingComponent();

        $this->assertSame(1, (int) ($c->viewData('counts')['conflict'] ?? 0));
        $this->assertSame(1, $c->viewData('agreement')['diverge']);
    }
}
