<?php

namespace Tests\Feature\Classify;

use App\Models\AnswerCache;
use App\Models\GoldLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The gold / memory fix that ships with sorter v2: listed lines that name only a document, a
// person, a firm… leave memory (live + parked pool) and the gold; a listed real service becomes a
// service; nothing else — other names, a Testing dataset's own memory, other gold sources — moves.
class GoldTrashConventionFixTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_02_200000_apply_trash_convention_to_gold.php');
    }

    private function memory(int $scope, string $name, bool $service, ?string $heading, string $source = 'gold'): AnswerCache
    {
        return AnswerCache::create(['test_dataset_id' => $scope, 'source' => $source, 'name' => $name,
            'name_key' => AnswerCache::keyFor($name), 'heading' => $heading, 'is_service' => $service]);
    }

    public function test_listed_trash_leaves_memory_and_gold_and_a_real_service_is_fixed(): void
    {
        $trash = $this->memory(0, 'Hesab no 2025/2198', true, null, 'ai_resolved_grounded');
        $parked = $this->memory(-1, 'KRYZHNAIA NATALI', false, '0810');
        $service = $this->memory(0, 'Uroloji USM', false, '9018', 'auto:consensus');
        $other = $this->memory(0, 'Noutbuk', false, '8471');
        $testing = $this->memory(3, 'Hesab no 2025/2198', true, null);
        $gold = GoldLabel::create(['source' => 'gold', 'name' => 'KRYZHNAIA NATALI', 'name_key' => GoldLabel::keyFor('KRYZHNAIA NATALI'), 'heading' => '0810', 'is_service' => false]);
        $fedor = GoldLabel::create(['source' => 'fedor', 'name' => 'KRYZHNAIA NATALI', 'name_key' => GoldLabel::keyFor('KRYZHNAIA NATALI'), 'heading' => '0810', 'is_service' => false]);

        $this->migration()->up();

        $this->assertNull($trash->fresh());
        $this->assertNull($parked->fresh());
        $this->assertNull($gold->fresh());
        $this->assertTrue((bool) $service->fresh()->is_service);
        $this->assertNull($service->fresh()->heading);
        $this->assertNotNull($other->fresh());     // not on the list
        $this->assertNotNull($testing->fresh());   // a Testing dataset's own memory
        $this->assertNotNull($fedor->fresh());     // only the model-made gold is fixed

        $this->migration()->down();

        $back = AnswerCache::where('test_dataset_id', 0)->where('name_key', AnswerCache::keyFor('Hesab no 2025/2198'))->first();
        $this->assertTrue((bool) $back->is_service);
        $this->assertSame('ai_resolved_grounded', $back->source);
        $this->assertSame('0810', GoldLabel::where('source', 'gold')->where('name_key', GoldLabel::keyFor('KRYZHNAIA NATALI'))->value('heading'));
        $this->assertSame('9018', $service->fresh()->heading);
        $this->assertFalse((bool) $service->fresh()->is_service);
    }
}
