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

    /** The uploads list (/review). */
    private function actingComponent()
    {
        return Livewire::actingAs(User::factory()->create())->test(ReviewQueue::class);
    }

    /** One run's detail (/review/{batch}); 'all' aggregates every upload. */
    private function detailComponent(string $batch = 'all')
    {
        return Livewire::actingAs(User::factory()->create())->test(ReviewQueue::class, ['batch' => $batch]);
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

    public function test_confirming_a_4digit_heading_answer_works(): void
    {
        // The common case: an auto-resolved item carries a 4-digit heading with no exact
        // catalog leaf. Confirming it must succeed and preserve the heading.
        $item = ClassificationItem::create(['batch' => 'b', 'source_text' => 'Şpris', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '9018', 'kind' => 'good']);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(ReviewQueue::class)->call('confirmWith', $item->id, '9018');

        $item->refresh();
        $this->assertSame('confirmed', $item->resolution);
        $this->assertSame('9018', $item->final_code);   // heading preserved
        $this->assertNull($item->final_catalog_id);
        $this->assertSame($user->id, $item->confirmed_by);
    }

    public function test_confirm_all_covers_ai_resolved_items(): void
    {
        ClassificationItem::create(['batch' => 'up', 'source_text' => 'a', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '1104']);
        ClassificationItem::create(['batch' => 'up', 'source_text' => 'b', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'ai_resolved', 'final_code' => '9018']);

        Livewire::actingAs(User::factory()->create())->test(ReviewQueue::class)
            ->set('batch', 'up')->call('confirmAll');

        // Both the consensus (agreed) AND the web-search (ai_resolved) item get confirmed.
        $this->assertSame(2, ClassificationItem::where('batch', 'up')->where('resolution', 'confirmed')->count());
    }

    public function test_uploads_table_lists_batches_and_selecting_one_opens_its_run(): void
    {
        ClassificationItem::create(['batch' => 'up-alpha', 'source_text' => 'alpha item', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '8471']);
        // A TERMINAL conflict (the web-search resolver ran — a 'search' trace exists — and
        // couldn't settle it), so it counts as 'conflict', not the in-flight 'resolving'.
        $conflict = ClassificationItem::create(['batch' => 'up-alpha', 'source_text' => 'alpha 2', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'conflict', 'search_resolved_at' => now()]);
        $conflict->results()->create(['mechanism' => 'search', 'matched_code' => null, 'status' => 'needs_review']);
        ClassificationItem::create(['batch' => 'up-beta', 'source_text' => 'beta item', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'confirmed', 'final_code' => '1104']);

        // The list page shows every upload (the batch key appears in each row's run link).
        $c = $this->actingComponent()->assertOk()
            ->assertSee('up-alpha')->assertSee('up-beta')
            ->assertSee('resolved');                        // the result bar label

        // A batch carries its resolution breakdown for the result bar.
        $alpha = collect($c->viewData('uploads'))->firstWhere('key', 'up-alpha');
        $this->assertSame(2, $alpha->total);
        $this->assertSame(1, $alpha->resolved);
        $this->assertSame(1, $alpha->conflict);
        $this->assertSame(0, $alpha->resolving);
        $this->assertSame(50, $alpha->done);

        // Clicking an upload navigates to its run page.
        $this->actingComponent()->call('selectBatch', 'up-beta')
            ->assertRedirect(route('review.batch', ['batch' => 'up-beta']));

        // …which scopes the item list to that batch.
        $this->assertSame(1, $this->detailComponent('up-beta')->viewData('items')->total());
    }

    public function test_item_search_filters_items_by_name_on_the_run_page(): void
    {
        ClassificationItem::create(['batch' => 'b', 'source_text' => 'Red apple', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '8471']);
        ClassificationItem::create(['batch' => 'b', 'source_text' => 'Yellow banana', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '8471']);

        $c = Livewire::actingAs(User::factory()->create())->test(ReviewQueue::class, ['batch' => 'b'])->set('q', 'apple');

        $this->assertSame(1, $c->viewData('items')->total());
        $this->assertSame('Red apple', $c->viewData('items')->first()->source_text);
    }

    public function test_upload_search_filters_the_list_by_label(): void
    {
        \App\Models\ImportBatch::create(['key' => '11111111-1111-1111-1111-111111111111', 'label' => 'March invoices']);
        \App\Models\ImportBatch::create(['key' => '22222222-2222-2222-2222-222222222222', 'label' => 'April report']);
        ClassificationItem::create(['batch' => '11111111-1111-1111-1111-111111111111', 'source_text' => 'x', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '8471']);
        ClassificationItem::create(['batch' => '22222222-2222-2222-2222-222222222222', 'source_text' => 'y', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '8471']);

        $labels = $this->actingComponent()->set('q', 'March')->viewData('uploads')->pluck('label');

        $this->assertTrue($labels->contains('March invoices'));
        $this->assertFalse($labels->contains('April report'));
    }

    public function test_perpage_is_clamped_to_the_allowed_set(): void
    {
        ClassificationItem::create(['batch' => 'up-a', 'source_text' => 'a', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '8471']);

        // A crafted ?perPage=0 must not divide-by-zero the list; it clamps back to 10.
        $this->actingComponent()->set('perPage', 0)->assertOk()->assertSet('perPage', 10);
    }

    public function test_all_uploads_row_sums_every_upload(): void
    {
        ClassificationItem::create(['batch' => 'up-a', 'source_text' => 'a', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '8471', 'memory_promoted_at' => now()]);
        ClassificationItem::create(['batch' => 'up-b', 'source_text' => 'b', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'confirmed', 'final_code' => '1104', 'memory_promoted_at' => now()]);
        ClassificationItem::create(['batch' => 'up-b', 'source_text' => 'c', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'conflict']);

        $all = $this->actingComponent()->viewData('allRow');
        $this->assertSame(3, $all->total);       // every item across both uploads
        $this->assertSame(2, $all->memory);      // two promoted into memory
    }

    public function test_table_shows_the_deciding_source(): void
    {
        // Web-search resolved → the "Found by" column shows "web research"; no raw mechanisms.
        $item = ClassificationItem::create(['batch' => 'b', 'source_text' => 'x', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'ai_resolved', 'final_code' => '8528', 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '8528723000', 'status' => 'auto_confirmed', 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => '8471300000', 'status' => 'auto_confirmed', 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'search', 'matched_code' => '8528', 'status' => 'auto_confirmed', 'kind' => 'good']);

        $this->detailComponent()->call('setFilter', 'all')
            ->assertSee('Found by')
            ->assertSee('web research')  // the deciding source
            ->assertSee('ai resolved')   // status
            ->assertDontSee('vector')->assertDontSee('broker'); // no raw mechanism names in the table
    }

    public function test_table_shows_memory_as_the_source_for_a_cache_hit(): void
    {
        $item = ClassificationItem::create(['batch' => 'b', 'source_text' => 'c', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '1104']);
        $item->results()->create(['mechanism' => 'cache', 'matched_code' => '1104', 'status' => 'auto_confirmed', 'kind' => 'good']);

        $this->detailComponent()->call('setFilter', 'all')
            ->assertSee('memory')
            ->assertDontSee('local ai')
            ->assertDontSee('web research');
    }

    public function test_a_corrected_cache_hit_is_not_still_credited_to_memory(): void
    {
        // A cache hit whose code a human later corrected to a different heading keeps its
        // stale mechanism='cache' row — the "Found by" column must credit the current
        // answer's real source, not the cache, which never proposed the corrected code.
        $item = ClassificationItem::create(['batch' => 'b', 'source_text' => 'x', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'confirmed', 'final_code' => '8471', 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'cache', 'matched_code' => '1104', 'status' => 'auto_confirmed', 'kind' => 'good']);

        $this->detailComponent()->call('setFilter', 'all')
            ->assertSee('8471')          // the human's corrected code is shown
            ->assertDontSee('memory');   // ...but NOT credited to the cache
    }

    public function test_dropdown_confirms_a_4digit_heading_correction(): void
    {
        // heading 8528 is a real heading (seeded in setUp).
        $item = ClassificationItem::create(['batch' => 'b', 'source_text' => 'tv', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'conflict', 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '8528723000', 'status' => 'needs_review', 'kind' => 'good', 'candidates' => [['code' => '8528723000']]]);

        // Confirm at the 4-digit heading 8528 (a real heading) — a correction, not the item's own answer.
        Livewire::actingAs(User::factory()->create())->test(ReviewQueue::class)->call('confirmWith', $item->id, '8528');

        $item->refresh();
        $this->assertSame('confirmed', $item->resolution);
        $this->assertSame('8528', $item->final_code);
        $this->assertNull($item->final_catalog_id);
    }

    public function test_agreed_and_ai_resolved_merge_into_found(): void
    {
        $this->agreedItem(); // resolution = agreed
        ClassificationItem::create(['batch' => 'b', 'source_text' => 'x1', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'ai_resolved', 'final_code' => '8471', 'kind' => 'good']);

        $c = $this->detailComponent();
        $counts = $c->viewData('counts');

        $this->assertSame(2, (int) ($counts['found'] ?? 0));           // agreed + ai_resolved merged
        $this->assertArrayNotHasKey('agreed', $counts->toArray());     // individual keys folded away
        $this->assertArrayNotHasKey('ai_resolved', $counts->toArray());

        $c->call('setFilter', 'found');
        $this->assertSame(2, $c->viewData('items')->total());          // the Found filter returns both
    }

    public function test_selecting_a_batch_renders_the_funnel_statistics_block(): void
    {
        ClassificationItem::create(['batch' => 'up-x', 'source_text' => 'a', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '8471', 'answered_at' => now()]);

        Livewire::actingAs(User::factory()->create())->test(ReviewQueue::class)
            ->set('batch', 'up-x')
            ->assertOk()
            ->assertSee('Batch statistics')
            ->assertSee('Recognition time')
            ->assertSee('Sent to memory & training');

        // The all-uploads view has no single batch → no per-batch stats block.
        Livewire::actingAs(User::factory()->create())->test(ReviewQueue::class)
            ->set('batch', 'all')
            ->assertOk()
            ->assertDontSee('Batch statistics');
    }

    public function test_in_flight_conflicts_are_counted_as_resolving_not_needs_attention(): void
    {
        // Pin the resolver on — deterministic regardless of the ambient env (CI defaults off).
        config()->set('classify.search_resolver.enabled', true);

        // In flight: conflict, resolver still running (no 'search' trace yet).
        ClassificationItem::create(['batch' => 'b', 'source_text' => 'still searching', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'conflict', 'search_resolved_at' => now()]);
        // Terminal: conflict the resolver already ran on and could not settle.
        $terminal = ClassificationItem::create(['batch' => 'b', 'source_text' => 'gave up', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'conflict', 'search_resolved_at' => now()]);
        $terminal->results()->create(['mechanism' => 'search', 'matched_code' => null, 'status' => 'needs_review']);

        $c = $this->detailComponent();
        $counts = $c->viewData('counts');

        // Only the terminal conflict is "needs attention"; the in-flight one is 'resolving'.
        $this->assertSame(1, $c->viewData('openCount'));
        $this->assertSame(1, (int) ($counts['resolving'] ?? 0));
        $this->assertSame(1, (int) ($counts['conflict'] ?? 0));

        // The 'open' filter list excludes the in-flight conflict…
        $c->call('setFilter', 'open');
        $this->assertSame(1, $c->viewData('items')->total());
        // …and the 'resolving' filter surfaces exactly it.
        $c->call('setFilter', 'resolving');
        $this->assertSame(1, $c->viewData('items')->total());
        $this->assertSame('still searching', $c->viewData('items')->first()->source_text);
    }
}
