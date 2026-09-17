<?php

namespace Tests\Feature\Classify;

use App\Livewire\Catalog;
use App\Livewire\MemoryItem;
use App\Models\AnswerCache;
use App\Models\ClassificationItem;
use App\Models\RubricatorNode;
use App\Models\User;
use App\Services\Classify\AnswerCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogMemoryTest extends TestCase
{
    use RefreshDatabase;

    private function memory(string $name, string $heading = '1104', int $scope = 0): AnswerCache
    {
        return AnswerCache::create([
            'test_dataset_id' => $scope, 'source' => 'fedor', 'name' => $name,
            'name_key' => AnswerCache::keyFor($name), 'heading' => $heading, 'is_service' => false,
        ]);
    }

    private function seedRubricator(): void
    {
        RubricatorNode::create(['code' => '11', 'level' => 1, 'kind' => 'good', 'title' => 'Milling products']);
        RubricatorNode::create(['code' => '1104', 'level' => 2, 'kind' => 'good', 'title' => 'Worked cereal grains']);
    }

    public function test_a_production_cache_hit_increments_the_hit_counter(): void
    {
        $this->memory('Oat flakes 1kg');
        $item = ClassificationItem::create(['batch' => 'b', 'source_text' => 'Oat flakes 1kg', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'pending']);

        app(AnswerCacheService::class)->apply($item);

        $row = AnswerCache::where('name_key', AnswerCache::keyFor('Oat flakes 1kg'))->first();
        $this->assertSame(1, (int) $row->hits);
        $this->assertNotNull($row->last_hit_at);
    }

    public function test_a_test_dataset_hit_does_not_touch_the_production_counter(): void
    {
        $prod = $this->memory('Oat flakes 1kg', scope: 0);
        $this->memory('Oat flakes 1kg', scope: 7); // dataset copy
        $item = ClassificationItem::create(['batch' => 'b', 'source_text' => 'Oat flakes 1kg', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'pending']);

        app(AnswerCacheService::class)->apply($item, 7);

        $this->assertSame(0, (int) $prod->fresh()->hits);
    }

    public function test_tree_lists_chapters_then_positions_then_leaves(): void
    {
        $this->seedRubricator();
        $leaf = $this->memory('Oat flakes 1kg', '1104');

        $c = Livewire::actingAs(User::factory()->create())->test(Catalog::class);
        $this->assertTrue($c->viewData('chapters')->contains(fn ($ch) => $ch->code === '11' && $ch->count === 1));

        $c->call('toggleChapter', '11');
        $this->assertTrue(collect($c->viewData('positionsByChapter')['11'])->contains(fn ($p) => $p->code === '1104'));

        $c->call('togglePosition', '1104');
        $this->assertTrue(collect($c->viewData('leavesByPosition')['1104'])->contains(fn ($l) => $l->id === $leaf->id));
    }

    public function test_a_service_with_a_heading_is_not_double_counted_in_a_chapter(): void
    {
        $this->seedRubricator();
        $this->memory('Oat flakes 1kg', '1104');                       // a good in chapter 11
        AnswerCache::create([                                          // a service that kept a heading
            'test_dataset_id' => 0, 'source' => 'confirmed', 'name' => 'Milling service',
            'name_key' => AnswerCache::keyFor('Milling service'), 'heading' => '1104', 'is_service' => true,
        ]);

        $c = Livewire::actingAs(User::factory()->create())->test(Catalog::class);
        $ch11 = $c->viewData('chapters')->firstWhere('code', '11');
        $this->assertSame(1, $ch11->count);   // only the good — the service is not double-counted
    }

    public function test_search_returns_matching_memory_entries(): void
    {
        $this->memory('Zeytun yağı 1L', '1509');

        Livewire::actingAs(User::factory()->create())->test(Catalog::class)
            ->set('q', 'Zeytun')
            ->assertSee('Zeytun');
    }

    public function test_memory_card_changes_the_code_and_logs_it(): void
    {
        $cache = $this->memory('Oat flakes 1kg', '1104');

        Livewire::actingAs(User::factory()->create())->test(MemoryItem::class, ['cache' => $cache])
            ->set('newCode', '2201')
            ->call('changeCode');

        $this->assertSame('2201', $cache->fresh()->heading);
        $this->assertDatabaseHas('activity_log', ['action' => 'memory.code.changed', 'subject_id' => $cache->id]);
    }

    public function test_memory_card_removes_the_entry(): void
    {
        $cache = $this->memory('Oat flakes 1kg', '1104');

        Livewire::actingAs(User::factory()->create())->test(MemoryItem::class, ['cache' => $cache])
            ->call('deleteEntry')
            ->assertRedirect(route('catalog'));

        $this->assertDatabaseMissing('answer_cache', ['id' => $cache->id]);
        $this->assertDatabaseHas('activity_log', ['action' => 'memory.entry.removed', 'subject_id' => $cache->id]);
    }
}
