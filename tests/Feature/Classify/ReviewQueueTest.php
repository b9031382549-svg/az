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

    public function test_heading_mode_reprojects_a_full_code_conflict_as_converged(): void
    {
        // Two mechanisms diverge on the full 10-digit code but share the 4-digit
        // heading 8471 — a "conflict" at full detail, a convergence at the heading.
        CatalogCode::create(['code' => '8471490000', 'name' => 'kompüter', 'kind' => 'good', 'chapter' => '84', 'position' => '8471', 'subposition' => '847149', 'is_active' => true]);
        $item = ClassificationItem::create([
            'batch' => 'b', 'source_text' => 'device', 'source_hash' => bin2hex(random_bytes(32)),
            'resolution' => 'conflict',
        ]);
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '8471300000', 'status' => 'auto_confirmed', 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => '8471490000', 'status' => 'auto_confirmed', 'kind' => 'good']);

        $c = $this->actingComponent();

        // Full code (default): the item reads as a conflict.
        $this->assertSame(1, (int) ($c->viewData('counts')['conflict'] ?? 0));
        $this->assertSame(0, (int) ($c->viewData('counts')['agreed'] ?? 0));

        // 4-digit heading: the SAME stored data now converges → agreed.
        $c->call('setCodeMode', 'heading');
        $this->assertSame('agreed', $c->viewData('vmap')[$item->id]);
        $this->assertSame(1, (int) ($c->viewData('counts')['agreed'] ?? 0));
        $this->assertSame(0, (int) ($c->viewData('counts')['conflict'] ?? 0));
        $this->assertSame(4, $c->viewData('agreement')['n']);
        $this->assertSame(1, $c->viewData('agreement')['converge']);
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

    public function test_dispatched_but_unjudged_conflict_shows_as_waiting(): void
    {
        // Judge dispatched (adjudicated_at) but no verdict row yet → "waiting", not conflict.
        ClassificationItem::create(['batch' => 'b', 'source_text' => 'pending judge', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'conflict', 'adjudicated_at' => now()]);
        // A genuine conflict the judge is not coming for (never dispatched).
        ClassificationItem::create(['batch' => 'b', 'source_text' => 'real conflict', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'conflict']);

        $c = $this->actingComponent();
        $counts = $c->viewData('counts');

        $this->assertSame(1, (int) ($counts['waiting'] ?? 0));
        $this->assertSame(1, (int) ($counts['conflict'] ?? 0));   // genuine only, not 2
        $this->assertSame(1, $c->viewData('openCount'));          // "Needs attention" excludes waiting

        $c->call('setFilter', 'waiting')->assertSee('pending judge')->assertSee('Waiting')->assertDontSee('real conflict');
        $c->call('setFilter', 'open')->assertSee('real conflict')->assertDontSee('pending judge');
    }

    public function test_agreed_ai_resolved_and_ai_proposed_merge_into_found(): void
    {
        $this->agreedItem(); // resolution = agreed
        ClassificationItem::create(['batch' => 'b', 'source_text' => 'x1', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'ai_resolved', 'final_code' => '8471300000', 'kind' => 'good']);
        $proposed = ClassificationItem::create(['batch' => 'b', 'source_text' => 'x2', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'conflict']);
        $proposed->adjudications()->create(['resolution_before' => 'conflict', 'model' => 'm', 'prompt_version' => 'j4', 'mode' => 'active', 'verdict' => 'resolved', 'winning_code' => '8471300000', 'winning_kind' => 'good', 'stable' => true, 'holdout' => true, 'applied' => false]);

        $c = $this->actingComponent();
        $counts = $c->viewData('counts');

        $this->assertSame(3, (int) ($counts['found'] ?? 0));           // all three merged
        $this->assertArrayNotHasKey('agreed', $counts->toArray());     // individual keys gone in full mode
        $this->assertArrayNotHasKey('ai_resolved', $counts->toArray());

        $c->call('setFilter', 'found');
        $this->assertSame(3, $c->viewData('items')->total());          // the Found filter returns all three
    }

    public function test_ai_proposed_conflict_is_carved_out_of_the_conflict_count(): void
    {
        // A conflict the adjudicator confidently RESOLVED (held out, not applied) —
        // reads as "AI proposed", not a raw conflict.
        $proposed = ClassificationItem::create(['batch' => 'b', 'source_text' => 'diamar', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'conflict']);
        $proposed->adjudications()->create(['resolution_before' => 'conflict', 'model' => 'openai/gpt-oss-120b', 'prompt_version' => 'j1', 'mode' => 'active', 'verdict' => 'resolved', 'winning_code' => '3105300000', 'winning_kind' => 'good', 'stable' => true, 'holdout' => true, 'applied' => false]);

        // A genuine conflict the adjudicator could not resolve.
        ClassificationItem::create(['batch' => 'b', 'source_text' => 'raunatin', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'conflict']);

        $c = $this->actingComponent();
        $counts = $c->viewData('counts');

        $this->assertSame(1, (int) ($counts['conflict'] ?? 0));   // genuine conflict only, not 2
        $this->assertSame(1, (int) ($counts['found'] ?? 0));      // the AI proposal counts as "found"
        $this->assertSame(1, $c->viewData('openCount'));          // "Needs attention" excludes the proposal

        $c->call('setFilter', 'found')->assertSee('diamar')->assertDontSee('raunatin');
        $c->call('setFilter', 'open')->assertSee('raunatin')->assertDontSee('diamar');
    }

    public function test_heading_mode_keeps_a_cross_heading_conflict_divergent(): void
    {
        // Different headings (8471 vs 8528) → still a conflict even at 4 digits.
        $item = ClassificationItem::create([
            'batch' => 'b', 'source_text' => 'device', 'source_hash' => bin2hex(random_bytes(32)),
            'resolution' => 'conflict',
        ]);
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '8471300000', 'status' => 'auto_confirmed', 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => '8528720000', 'status' => 'auto_confirmed', 'kind' => 'good']);

        $c = $this->actingComponent();
        $c->call('setCodeMode', 'heading');

        $this->assertSame('conflict', $c->viewData('vmap')[$item->id]);
        $this->assertSame(1, (int) ($c->viewData('counts')['conflict'] ?? 0));
    }
}
