<?php

namespace Tests\Feature\Classify;

use App\Livewire\ClassificationDecision;
use App\Models\ClassificationItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClassificationDecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_both_mechanism_traces(): void
    {
        $item = ClassificationItem::create([
            'batch' => 'b', 'source_text' => 'Dell Latitude noutbuk',
            'source_hash' => bin2hex(random_bytes(32)), 'resolution' => 'conflict',
        ]);
        $item->results()->create([
            'mechanism' => 'vector', 'matched_code' => '8471300000', 'confidence' => 0.9, 'status' => 'needs_review',
            'candidates' => [['code' => '8471300000', 'name' => 'noutbuk', 'score' => 0.5, 'semantic_sim' => 0.7]],
            'trace' => [
                'input' => 'Dell Latitude noutbuk', 'queries' => ['noutbuk kompüter'],
                'candidates' => [['code' => '8471300000', 'name' => 'noutbuk', 'score' => 0.5, 'semantic_sim' => 0.7]],
                'rerank' => ['tier' => 2, 'model' => 'openai/gpt-4o', 'code' => '8471300000', 'confidence' => 0.9, 'reason' => 'laptop'],
                'gate' => ['confidence' => 0.9, 'auto_confirm' => 0.8, 'semantic_sim' => 0.7, 'min_semantic' => 0.5, 'status' => 'auto_confirmed'],
            ],
        ]);
        $item->results()->create([
            'mechanism' => 'broker', 'matched_code' => '8528720000', 'confidence' => 0.6, 'status' => 'needs_review',
            'trace' => [
                'input' => 'Dell Latitude noutbuk', 'essence' => 'noutbuk',
                'steps' => [
                    ['type' => 'fork', 'options' => [['code' => '84', 'title' => 'Machinery', 'samples' => 'computers']], 'criterion' => 'function', 'chosen' => '85', 'confidence' => 0.6, 'decisive' => true, 'accepted' => true],
                    ['type' => 'leaf', 'options' => [['code' => '8528720000', 'name' => 'tv']], 'chosen' => '8528720000', 'confidence' => 0.6],
                ],
                'gate' => ['confidence' => 0.6, 'auto_confirm' => 0.8, 'semantic_sim' => null, 'min_semantic' => 0.5, 'status' => 'needs_review'],
            ],
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(ClassificationDecision::class, ['item' => $item])
            ->assertOk()
            ->assertSee('Dell Latitude noutbuk')
            ->assertSee('vector')
            ->assertSee('broker')
            ->assertSee('function')          // broker criterion
            ->assertSee('noutbuk kompüter');  // vector query
    }

    public function test_renders_light_fallback_without_trace(): void
    {
        $item = ClassificationItem::create([
            'batch' => 'b', 'source_text' => 'legacy item',
            'source_hash' => bin2hex(random_bytes(32)), 'resolution' => 'agreed', 'final_code' => '8471300000',
        ]);
        $item->results()->create([
            'mechanism' => 'vector', 'matched_code' => '8471300000', 'status' => 'auto_confirmed',
            'candidates' => [['code' => '8471300000', 'name' => 'noutbuk']], // no trace column
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(ClassificationDecision::class, ['item' => $item])
            ->assertOk()
            ->assertSee('legacy item');
    }
}
