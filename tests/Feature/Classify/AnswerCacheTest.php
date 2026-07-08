<?php

namespace Tests\Feature\Classify;

use App\Models\AnswerCache;
use App\Models\ClassificationItem;
use App\Models\GoldLabel;
use App\Services\Classify\AnswerCacheService;
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
}
