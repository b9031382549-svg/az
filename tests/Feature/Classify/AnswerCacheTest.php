<?php

namespace Tests\Feature\Classify;

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
}
