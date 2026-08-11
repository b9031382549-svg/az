<?php

namespace Tests\Feature\Classify;

use App\Livewire\Concerns\ConfirmsClassifications;
use App\Models\AnswerCache;
use App\Models\ClassificationItem;
use App\Models\GoldLabel;
use App\Models\TestDataset;
use App\Models\TestRun;
use App\Services\Classify\AnswerCacheService;
use App\Services\Classify\Consensus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnswerCacheTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $text): ClassificationItem
    {
        return ClassificationItem::create(['batch' => 'b', 'source_text' => $text, 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'pending']);
    }

    public function test_seed_from_fedor_gold_then_lookup_by_normalized_name(): void
    {
        GoldLabel::create(['source' => 'fedor', 'name' => 'BARLEY PEARL 500g', 'name_key' => GoldLabel::keyFor('BARLEY PEARL 500g'), 'heading' => '1104', 'is_service' => false, 'tier' => 'validated']);
        GoldLabel::create(['source' => 'fedor', 'name' => 'Moon Hotel', 'name_key' => GoldLabel::keyFor('Moon Hotel'), 'heading' => null, 'is_service' => true, 'tier' => 'validated']);

        $this->artisan('cache:seed --source=fedor')->assertSuccessful();
        $this->assertDatabaseCount('answer_cache', 2);

        $svc = app(AnswerCacheService::class);
        $this->assertSame('1104', $svc->lookup('  barley   pearl 500G ')->heading); // normalized match
        $this->assertNull($svc->lookup('something never seen'));
    }

    public function test_seed_with_exclude_benchmarks_skips_held_out_names(): void
    {
        GoldLabel::create(['source' => 'gold', 'name' => 'Held Out Widget', 'name_key' => GoldLabel::keyFor('Held Out Widget'), 'heading' => '8471', 'is_service' => false, 'tier' => 'multi-family']);
        GoldLabel::create(['source' => 'gold', 'name' => 'Held Out Service Item', 'name_key' => GoldLabel::keyFor('Held Out Service Item'), 'heading' => null, 'is_service' => true, 'tier' => 'multi-family']);
        GoldLabel::create(['source' => 'gold', 'name' => 'Clean Widget', 'name_key' => GoldLabel::keyFor('Clean Widget'), 'heading' => '6302', 'is_service' => false, 'tier' => 'multi-family']);

        $csv = storage_path('app/test-holdout-'.bin2hex(random_bytes(4)).'.csv');
        $fh = fopen($csv, 'w');
        fputcsv($fh, ['name']);
        fputcsv($fh, ['Held Out Widget']);
        fclose($fh);

        $jsonl = storage_path('app/test-holdout-'.bin2hex(random_bytes(4)).'.jsonl');
        file_put_contents($jsonl, json_encode(['name' => 'Held Out Service Item', 'gold' => null])."\n");

        config()->set('classify.held_out_benchmarks', [$csv, $jsonl]);

        $this->artisan('cache:seed', ['--source' => 'gold', '--exclude-benchmarks' => true])->assertSuccessful();

        $this->assertDatabaseCount('answer_cache', 1);
        $this->assertNotNull(AnswerCache::where('name_key', AnswerCache::keyFor('Clean Widget'))->first());
        $this->assertNull(AnswerCache::where('name_key', AnswerCache::keyFor('Held Out Widget'))->first());
        $this->assertNull(AnswerCache::where('name_key', AnswerCache::keyFor('Held Out Service Item'))->first());

        @unlink($csv);
        @unlink($jsonl);
    }

    public function test_seed_without_exclude_benchmarks_flag_seeds_everything(): void
    {
        GoldLabel::create(['source' => 'gold', 'name' => 'Held Out Widget', 'name_key' => GoldLabel::keyFor('Held Out Widget'), 'heading' => '8471', 'is_service' => false, 'tier' => 'multi-family']);

        $csv = storage_path('app/test-holdout-'.bin2hex(random_bytes(4)).'.csv');
        $fh = fopen($csv, 'w');
        fputcsv($fh, ['name']);
        fputcsv($fh, ['Held Out Widget']);
        fclose($fh);
        config()->set('classify.held_out_benchmarks', [$csv]);

        // No --exclude-benchmarks — default behavior is unchanged, the held-out file is ignored.
        $this->artisan('cache:seed', ['--source' => 'gold'])->assertSuccessful();

        $this->assertDatabaseCount('answer_cache', 1);

        @unlink($csv);
    }

    public function test_seed_warns_but_does_not_fail_when_a_held_out_file_is_missing(): void
    {
        GoldLabel::create(['source' => 'gold', 'name' => 'Some Widget', 'name_key' => GoldLabel::keyFor('Some Widget'), 'heading' => '8471', 'is_service' => false, 'tier' => 'multi-family']);
        config()->set('classify.held_out_benchmarks', [storage_path('app/does-not-exist.csv')]);

        $this->artisan('cache:seed', ['--source' => 'gold', '--exclude-benchmarks' => true])->assertSuccessful();

        // A missing held-out file excludes NOTHING (fails open, not silently) — the row is seeded.
        $this->assertDatabaseCount('answer_cache', 1);
    }

    public function test_seeding_a_new_source_never_overwrites_an_existing_different_source_answer(): void
    {
        GoldLabel::create(['source' => 'fedor', 'name' => 'Shared Product', 'name_key' => GoldLabel::keyFor('Shared Product'), 'heading' => '1104', 'is_service' => false, 'tier' => 'validated']);
        $this->artisan('cache:seed', ['--source' => 'fedor'])->assertSuccessful();

        // A broader 'gold' import disagrees on the same name — must NOT clobber fedor's answer.
        GoldLabel::create(['source' => 'gold', 'name' => 'Shared Product', 'name_key' => GoldLabel::keyFor('Shared Product'), 'heading' => '9999', 'is_service' => false, 'tier' => 'multi-family']);
        $this->artisan('cache:seed', ['--source' => 'gold'])->assertSuccessful();

        $row = AnswerCache::where('name_key', AnswerCache::keyFor('Shared Product'))->first();
        $this->assertSame('fedor', $row->source);
        $this->assertSame('1104', $row->heading);
        $this->assertDatabaseCount('answer_cache', 1); // no duplicate row for the gold side either
    }

    public function test_reseeding_the_same_source_still_refreshes_its_own_rows(): void
    {
        GoldLabel::create(['source' => 'fedor', 'name' => 'Refreshable Product', 'name_key' => GoldLabel::keyFor('Refreshable Product'), 'heading' => '1104', 'is_service' => false, 'tier' => 'claude']);
        $this->artisan('cache:seed', ['--source' => 'fedor'])->assertSuccessful();

        // The reference gets corrected upstream; re-seeding the SAME source must pick it up.
        GoldLabel::where('name_key', GoldLabel::keyFor('Refreshable Product'))->update(['heading' => '2005', 'tier' => 'validated']);
        $this->artisan('cache:seed', ['--source' => 'fedor'])->assertSuccessful();

        $row = AnswerCache::where('name_key', AnswerCache::keyFor('Refreshable Product'))->first();
        $this->assertSame('2005', $row->heading);
    }

    public function test_apply_resolves_a_good_at_the_4_digit_heading(): void
    {
        AnswerCache::create(['source' => 'fedor', 'name' => 'Barley', 'name_key' => AnswerCache::keyFor('Barley'), 'heading' => '1104', 'is_service' => false]);
        $item = $this->item('Barley');

        $this->assertTrue(app(AnswerCacheService::class)->apply($item));

        $item->refresh();
        $this->assertSame('agreed', $item->resolution);
        $this->assertSame('1104', $item->final_code);
        $this->assertSame('good', $item->kind);
        $this->assertSame('1104', $item->results()->where('mechanism', 'cache')->first()->matched_code);
    }

    public function test_apply_resolves_a_service_at_99(): void
    {
        AnswerCache::create(['source' => 'fedor', 'name' => 'Hotel Booking', 'name_key' => AnswerCache::keyFor('Hotel Booking'), 'heading' => null, 'is_service' => true]);
        $item = $this->item('Hotel Booking');

        $this->assertTrue(app(AnswerCacheService::class)->apply($item));

        $item->refresh();
        $this->assertSame('service', $item->kind);
        $this->assertSame('99', $item->final_code);
        $this->assertSame('agreed', $item->resolution);
    }

    public function test_a_miss_leaves_the_item_pending(): void
    {
        $item = $this->item('nothing cached here');

        $this->assertFalse(app(AnswerCacheService::class)->apply($item));
        $this->assertSame('pending', $item->fresh()->resolution);
    }

    public function test_disabled_cache_never_hits(): void
    {
        config()->set('classify.cache.enabled', false);
        AnswerCache::create(['source' => 'fedor', 'name' => 'Barley', 'name_key' => AnswerCache::keyFor('Barley'), 'heading' => '1104', 'is_service' => false]);

        $this->assertNull(app(AnswerCacheService::class)->lookup('Barley'));
    }

    // --- Memory promotion (write-back of unanimous consensus) ---------------------------

    /** @param array<string, mixed> $overrides */
    private function agreedItem(string $text, string $heading = '1104', string $kind = 'good', array $overrides = []): ClassificationItem
    {
        return ClassificationItem::create(array_merge([
            'batch' => 'b', 'source_text' => $text, 'source_hash' => bin2hex(random_bytes(16)),
            'resolution' => 'agreed', 'final_code' => $heading, 'kind' => $kind,
        ], $overrides));
    }

    private function promoteLive(): void
    {
        config()->set('classify.memory_promotion.enabled', true);
        config()->set('classify.memory_promotion.shadow', false);
        config()->set('classify.memory_promotion.min_agreement', 2);
    }

    public function test_promote_writes_a_unanimous_good_to_production_memory(): void
    {
        $this->promoteLive();
        $item = $this->agreedItem('Some New Brand Cornflakes');

        app(AnswerCacheService::class)->promote($item, ['count' => 3, 'total' => 3, 'heading' => '1104', 'kind' => 'good']);

        $row = AnswerCache::where('test_dataset_id', 0)->where('name_key', AnswerCache::keyFor('Some New Brand Cornflakes'))->first();
        $this->assertNotNull($row);
        $this->assertSame('1104', $row->heading);
        $this->assertFalse((bool) $row->is_service);
        $this->assertSame('auto:consensus', $row->source);
    }

    public function test_promote_writes_a_service_heading_null(): void
    {
        $this->promoteLive();
        $item = $this->agreedItem('Some Consulting Service', '99', 'service');

        app(AnswerCacheService::class)->promote($item, ['count' => 2, 'total' => 2, 'heading' => '99', 'kind' => 'service']);

        $row = AnswerCache::where('test_dataset_id', 0)->where('name_key', AnswerCache::keyFor('Some Consulting Service'))->first();
        $this->assertNotNull($row);
        $this->assertNull($row->heading);
        $this->assertTrue((bool) $row->is_service);
    }

    public function test_promote_does_not_write_a_bare_majority(): void
    {
        $this->promoteLive();
        $item = $this->agreedItem('Ambiguous Item');

        // 2 of 3 agreed — not unanimous (count !== total).
        app(AnswerCacheService::class)->promote($item, ['count' => 2, 'total' => 3, 'heading' => '1104', 'kind' => 'good']);

        $this->assertDatabaseCount('answer_cache', 0);
    }

    public function test_promote_does_not_write_a_lone_single_mechanism(): void
    {
        $this->promoteLive();
        $item = $this->agreedItem('Only One Voted');

        // Unanimous but only 1 mechanism ran — no independent corroboration (< min_agreement).
        app(AnswerCacheService::class)->promote($item, ['count' => 1, 'total' => 1, 'heading' => '1104', 'kind' => 'good']);

        $this->assertDatabaseCount('answer_cache', 0);
    }

    public function test_promote_in_shadow_mode_writes_nothing(): void
    {
        config()->set('classify.memory_promotion.enabled', true);
        config()->set('classify.memory_promotion.shadow', true);
        $item = $this->agreedItem('Shadowed Item');

        app(AnswerCacheService::class)->promote($item, ['count' => 3, 'total' => 3, 'heading' => '1104', 'kind' => 'good']);

        $this->assertDatabaseCount('answer_cache', 0);
    }

    public function test_promote_disabled_writes_nothing(): void
    {
        config()->set('classify.memory_promotion.enabled', false);
        $item = $this->agreedItem('Disabled Item');

        app(AnswerCacheService::class)->promote($item, ['count' => 3, 'total' => 3, 'heading' => '1104', 'kind' => 'good']);

        $this->assertDatabaseCount('answer_cache', 0);
    }

    // --- wouldPromote() / wouldPromoteGroundedSearch() (side-effect-free measurement,
    // used by the Testing funnel report) — must mirror promote()'s real gate exactly. ---

    public function test_would_promote_mirrors_promote_when_live(): void
    {
        $this->promoteLive();
        $item = $this->agreedItem('Would Promote Item');

        $this->assertTrue(app(AnswerCacheService::class)->wouldPromote($item, ['count' => 3, 'total' => 3, 'heading' => '1104', 'kind' => 'good']));
    }

    public function test_would_promote_is_false_for_a_bare_majority(): void
    {
        $this->promoteLive();
        $item = $this->agreedItem('Ambiguous Item');

        $this->assertFalse(app(AnswerCacheService::class)->wouldPromote($item, ['count' => 2, 'total' => 3, 'heading' => '1104', 'kind' => 'good']));
    }

    public function test_would_promote_is_false_in_shadow_mode_even_though_unanimous(): void
    {
        config()->set('classify.memory_promotion.enabled', true);
        config()->set('classify.memory_promotion.shadow', true);
        $item = $this->agreedItem('Shadowed Item');

        // Unlike a hypothetical "would qualify" count, this reflects what ACTUALLY
        // happens: shadow mode never writes, so wouldPromote() must read false.
        $this->assertFalse(app(AnswerCacheService::class)->wouldPromote($item, ['count' => 3, 'total' => 3, 'heading' => '1104', 'kind' => 'good']));
    }

    public function test_would_promote_is_false_when_disabled(): void
    {
        config()->set('classify.memory_promotion.enabled', false);
        $item = $this->agreedItem('Disabled Item');

        $this->assertFalse(app(AnswerCacheService::class)->wouldPromote($item, ['count' => 3, 'total' => 3, 'heading' => '1104', 'kind' => 'good']));
    }

    public function test_would_promote_grounded_search_true_when_confident_and_grounded(): void
    {
        config()->set('classify.memory_promotion.grounded_search.enabled', true);
        $item = $this->item('Laptop Item');
        $vector = $item->results()->create(['mechanism' => 'vector', 'matched_code' => '8471300000', 'kind' => 'good', 'status' => 'needs_review']);
        $search = $item->results()->create(['mechanism' => 'search', 'matched_code' => '8471900000', 'kind' => 'good', 'status' => 'auto_confirmed', 'confidence' => 0.95]);

        $this->assertTrue(app(AnswerCacheService::class)->wouldPromoteGroundedSearch($item, $search, collect([$vector])));
    }

    public function test_would_promote_grounded_search_false_when_ungrounded(): void
    {
        config()->set('classify.memory_promotion.grounded_search.enabled', true);
        $item = $this->item('Random Item');
        // Vector proposed something else entirely — search's heading doesn't overlap it.
        $vector = $item->results()->create(['mechanism' => 'vector', 'matched_code' => '620800', 'kind' => 'good', 'status' => 'needs_review']);
        $search = $item->results()->create(['mechanism' => 'search', 'matched_code' => '8471900000', 'kind' => 'good', 'status' => 'auto_confirmed', 'confidence' => 0.95]);

        $this->assertFalse(app(AnswerCacheService::class)->wouldPromoteGroundedSearch($item, $search, collect([$vector])));
    }

    public function test_would_promote_grounded_search_false_when_disabled(): void
    {
        config()->set('classify.memory_promotion.grounded_search.enabled', false);
        $item = $this->item('Laptop Item 2');
        $vector = $item->results()->create(['mechanism' => 'vector', 'matched_code' => '8471300000', 'kind' => 'good', 'status' => 'needs_review']);
        $search = $item->results()->create(['mechanism' => 'search', 'matched_code' => '8471900000', 'kind' => 'good', 'status' => 'auto_confirmed', 'confidence' => 0.95]);

        $this->assertFalse(app(AnswerCacheService::class)->wouldPromoteGroundedSearch($item, $search, collect([$vector])));
    }

    public function test_promote_never_overwrites_a_seeded_fedor_answer(): void
    {
        $this->promoteLive();
        // A verified seed already exists for this name.
        AnswerCache::create(['test_dataset_id' => 0, 'source' => 'fedor', 'name' => 'Barley',
            'name_key' => AnswerCache::keyFor('Barley'), 'heading' => '1104', 'is_service' => false]);
        $item = $this->agreedItem('Barley', '9999'); // consensus would say something else

        app(AnswerCacheService::class)->promote($item, ['count' => 3, 'total' => 3, 'heading' => '9999', 'kind' => 'good']);

        // insertOrIgnore on the unique (scope, name_key) key → the seed is untouched.
        $row = AnswerCache::where('test_dataset_id', 0)->where('name_key', AnswerCache::keyFor('Barley'))->first();
        $this->assertSame('fedor', $row->source);
        $this->assertSame('1104', $row->heading);
        $this->assertDatabaseCount('answer_cache', 1);
    }

    public function test_finalize_promotes_a_unanimous_prod_item(): void
    {
        $this->promoteLive();
        config()->set('classify.mechanisms.enabled', ['vector', 'broker', 'direct']);
        config()->set('classify.mechanisms.shadow', []);

        $item = $this->item('Brand New Widget');
        foreach (['vector', 'broker', 'direct'] as $m) {
            $item->results()->create(['mechanism' => $m, 'matched_code' => '847130', 'kind' => 'good', 'status' => 'auto_confirmed']);
        }

        app(Consensus::class)->finalize($item);

        $item->refresh();
        $this->assertSame('agreed', $item->resolution);
        $this->assertSame('8471', $item->final_code);
        $this->assertNotNull(AnswerCache::where('test_dataset_id', 0)
            ->where('name_key', AnswerCache::keyFor('Brand New Widget'))->where('source', 'auto:consensus')->first());
    }

    public function test_finalize_never_promotes_a_benchmark_gold_item(): void
    {
        $this->promoteLive();
        config()->set('classify.mechanisms.enabled', ['vector', 'broker', 'direct']);
        config()->set('classify.mechanisms.shadow', []);

        // benchmark:seed fans gold names through the prod pipeline on a "gold-<source>"
        // batch purely to MEASURE — a unanimous gold item must NOT leak into live memory.
        $item = ClassificationItem::create(['batch' => 'gold-ivan', 'source_text' => 'Gold Sample Item',
            'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'pending']);
        foreach (['vector', 'broker', 'direct'] as $m) {
            $item->results()->create(['mechanism' => $m, 'matched_code' => '847130', 'kind' => 'good', 'status' => 'auto_confirmed']);
        }

        app(Consensus::class)->finalize($item);

        $this->assertSame('agreed', $item->fresh()->resolution);
        $this->assertDatabaseCount('answer_cache', 0);
    }

    public function test_finalize_never_promotes_a_test_run_item_into_production(): void
    {
        $this->promoteLive();
        config()->set('classify.mechanisms.enabled', ['vector', 'broker', 'direct']);
        config()->set('classify.mechanisms.shadow', []);

        $dataset = TestDataset::create(['name' => 'ds', 'mechanisms' => ['enabled' => ['vector', 'broker', 'direct']]]);
        $run = TestRun::create(['test_dataset_id' => $dataset->id, 'description' => 'r',
            'mechanisms' => ['enabled' => ['vector', 'broker', 'direct'], 'shadow' => []], 'config' => [], 'status' => 'running']);

        $item = $this->item('Test Run Widget');
        $item->update(['test_run_id' => $run->id]); // a dataset test-run item
        foreach (['vector', 'broker', 'direct'] as $m) {
            $item->results()->create(['mechanism' => $m, 'matched_code' => '847130', 'kind' => 'good', 'status' => 'auto_confirmed']);
        }

        app(Consensus::class)->finalize($item);

        // A unanimous test item must NEVER touch the shared production memory (scope 0).
        $this->assertDatabaseCount('answer_cache', 0);
    }

    // --- Confirmed write-back (memory_promotion.confirmed) -------------------------------

    public function test_promote_confirmed_writes_a_good_to_production_memory(): void
    {
        config()->set('classify.memory_promotion.confirmed.enabled', true);
        $item = $this->agreedItem('Confirmed Widget', '8471', 'good', ['resolution' => 'confirmed']);

        app(AnswerCacheService::class)->promoteConfirmed($item);

        $row = AnswerCache::where('name_key', AnswerCache::keyFor('Confirmed Widget'))->first();
        $this->assertNotNull($row);
        $this->assertSame('8471', $row->heading);
        $this->assertSame('confirmed', $row->source);
    }

    public function test_promote_confirmed_is_off_by_default(): void
    {
        config()->set('classify.memory_promotion.confirmed.enabled', false);
        $item = $this->agreedItem('Off By Default Widget', '8471', 'good', ['resolution' => 'confirmed']);

        app(AnswerCacheService::class)->promoteConfirmed($item);

        $this->assertDatabaseCount('answer_cache', 0);
    }

    public function test_promote_confirmed_update_s_an_existing_wrong_answer_unlike_promote(): void
    {
        config()->set('classify.memory_promotion.confirmed.enabled', true);
        AnswerCache::create(['test_dataset_id' => 0, 'source' => 'auto:consensus', 'name' => 'Wrongly Cached Widget',
            'name_key' => AnswerCache::keyFor('Wrongly Cached Widget'), 'heading' => '9999', 'is_service' => false]);
        // A human catches and corrects the stale wrong answer.
        $item = $this->agreedItem('Wrongly Cached Widget', '8471', 'good', ['resolution' => 'confirmed']);

        app(AnswerCacheService::class)->promoteConfirmed($item);

        $row = AnswerCache::where('name_key', AnswerCache::keyFor('Wrongly Cached Widget'))->first();
        $this->assertSame('confirmed', $row->source);
        $this->assertSame('8471', $row->heading); // the fix propagated, not left stale
        $this->assertDatabaseCount('answer_cache', 1);
    }

    public function test_promote_confirmed_truncates_a_full_10_digit_confirmation_to_heading(): void
    {
        config()->set('classify.memory_promotion.confirmed.enabled', true);
        $item = $this->agreedItem('Full Code Widget', '8471301000', 'good', ['resolution' => 'confirmed']);

        app(AnswerCacheService::class)->promoteConfirmed($item);

        $this->assertSame('8471', AnswerCache::where('name_key', AnswerCache::keyFor('Full Code Widget'))->first()->heading);
    }

    public function test_promote_confirmed_writes_a_service(): void
    {
        config()->set('classify.memory_promotion.confirmed.enabled', true);
        $item = $this->agreedItem('Confirmed Service', '99', 'service', ['resolution' => 'confirmed']);

        app(AnswerCacheService::class)->promoteConfirmed($item);

        $row = AnswerCache::where('name_key', AnswerCache::keyFor('Confirmed Service'))->first();
        $this->assertNull($row->heading);
        $this->assertTrue((bool) $row->is_service);
    }

    public function test_confirming_an_item_via_the_trait_writes_to_memory(): void
    {
        config()->set('classify.memory_promotion.confirmed.enabled', true);
        $item = $this->item('Trait Confirmed Widget');
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '8471301000', 'kind' => 'good', 'status' => 'needs_review']);
        $item->update(['final_code' => '8471', 'kind' => 'good']);

        $tester = new class
        {
            use ConfirmsClassifications;

            public function confirm(ClassificationItem $item, string $code): bool
            {
                return $this->applyConfirm($item, $code);
            }
        };
        $this->assertTrue($tester->confirm($item, '8471'));

        $this->assertNotNull(AnswerCache::where('name_key', AnswerCache::keyFor('Trait Confirmed Widget'))->first());
    }

    // --- Reset memory to baseline (the classifier's "Reset memory" button) ---------------

    public function test_reset_to_baseline_keeps_gold_and_deletes_everything_else_in_production_scope(): void
    {
        AnswerCache::create(['test_dataset_id' => 0, 'source' => 'gold', 'name' => 'Gold Item', 'name_key' => AnswerCache::keyFor('Gold Item'), 'heading' => '1104', 'is_service' => false]);
        AnswerCache::create(['test_dataset_id' => 0, 'source' => 'fedor', 'name' => 'Fedor Item', 'name_key' => AnswerCache::keyFor('Fedor Item'), 'heading' => '1104', 'is_service' => false]);
        AnswerCache::create(['test_dataset_id' => 0, 'source' => 'auto:consensus', 'name' => 'Auto Item', 'name_key' => AnswerCache::keyFor('Auto Item'), 'heading' => '1104', 'is_service' => false]);

        $deleted = app(AnswerCacheService::class)->resetToBaseline();

        $this->assertSame(2, $deleted);
        $this->assertDatabaseCount('answer_cache', 1);
        $this->assertSame('gold', AnswerCache::first()->source);
    }

    public function test_reset_to_baseline_never_touches_test_dataset_memory(): void
    {
        $dataset = TestDataset::create(['name' => 'ds', 'mechanisms' => ['enabled' => ['vector']]]);
        AnswerCache::create(['test_dataset_id' => 0, 'source' => 'fedor', 'name' => 'Prod Item', 'name_key' => AnswerCache::keyFor('Prod Item'), 'heading' => '1104', 'is_service' => false]);
        AnswerCache::create(['test_dataset_id' => $dataset->id, 'source' => 'dataset-labels', 'name' => 'Test Item', 'name_key' => AnswerCache::keyFor('Test Item'), 'heading' => '1104', 'is_service' => false]);

        $deleted = app(AnswerCacheService::class)->resetToBaseline();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseCount('answer_cache', 1);
        $this->assertSame($dataset->id, AnswerCache::first()->test_dataset_id);
    }

    public function test_reset_to_baseline_respects_a_configured_baseline_source(): void
    {
        config()->set('classify.cache.baseline_source', 'ivan');
        AnswerCache::create(['test_dataset_id' => 0, 'source' => 'gold', 'name' => 'Gold Item', 'name_key' => AnswerCache::keyFor('Gold Item'), 'heading' => '1104', 'is_service' => false]);
        AnswerCache::create(['test_dataset_id' => 0, 'source' => 'ivan', 'name' => 'Ivan Item', 'name_key' => AnswerCache::keyFor('Ivan Item'), 'heading' => '1104', 'is_service' => false]);

        $deleted = app(AnswerCacheService::class)->resetToBaseline();

        $this->assertSame(1, $deleted);
        $this->assertSame('ivan', AnswerCache::first()->source);
    }

    public function test_reset_to_baseline_is_a_no_op_when_only_the_baseline_exists(): void
    {
        AnswerCache::create(['test_dataset_id' => 0, 'source' => 'gold', 'name' => 'Gold Item', 'name_key' => AnswerCache::keyFor('Gold Item'), 'heading' => '1104', 'is_service' => false]);

        $this->assertSame(0, app(AnswerCacheService::class)->resetToBaseline());
        $this->assertDatabaseCount('answer_cache', 1);
    }
}
