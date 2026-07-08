<?php

namespace Tests\Feature\Classify;

use App\Livewire\ClassificationDecision;
use App\Models\ClassificationItem;
use App\Models\GoldLabel;
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

    public function test_shows_the_gold_reference_when_the_name_matches(): void
    {
        GoldLabel::create(['source' => 'fedor', 'name' => 'RAUNATİN No10', 'name_key' => GoldLabel::keyFor('RAUNATİN No10'), 'heading' => '3004', 'is_service' => false, 'tier' => 'validated', 'category' => 'antihypertensive tablets']);
        $item = ClassificationItem::create(['batch' => 'b', 'source_text' => 'RAUNATİN No10', 'source_hash' => bin2hex(random_bytes(32)), 'resolution' => 'conflict']);
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '3004900000', 'status' => 'needs_review']);

        Livewire::actingAs(User::factory()->create())
            ->test(ClassificationDecision::class, ['item' => $item])
            ->assertOk()
            ->assertSee('Reference (gold)')
            ->assertSee('3004')
            ->assertSee('validated');
    }

    public function test_direct_mechanism_shows_its_recall_not_a_legacy_notice(): void
    {
        // The direct mechanism is a single cold LLM call with no step trace by design;
        // it must show its verdict/reason, not the "classified before the feature" notice.
        $item = ClassificationItem::create(['batch' => 'b', 'source_text' => 'RAUNATİN No10', 'source_hash' => bin2hex(random_bytes(32)), 'resolution' => 'conflict']);
        $item->results()->create(['mechanism' => 'direct', 'matched_code' => null, 'status' => 'no_match', 'confidence' => 0.9, 'explanation' => 'lacks a clear product noun; looks like a brand identifier']);

        Livewire::actingAs(User::factory()->create())
            ->test(ClassificationDecision::class, ['item' => $item])
            ->assertOk()
            ->assertSee('cold recall')
            ->assertSee('abstained')
            ->assertSee('lacks a clear product noun')
            ->assertDontSee('classified before the decision-flow feature');
    }

    public function test_search_resolver_row_shows_its_heading_not_a_legacy_notice(): void
    {
        // The search resolver writes a mechanism='search' row; even without a rich step
        // trace it must render its own branch (heading + reason), not the vector view or
        // the "classified before the feature" notice.
        $item = ClassificationItem::create(['batch' => 'b', 'source_text' => 'RAUNATİN No10', 'source_hash' => bin2hex(random_bytes(32)), 'resolution' => 'ai_resolved', 'final_code' => '3004']);
        $item->results()->create([
            'mechanism' => 'search', 'matched_code' => '3004', 'kind' => 'good', 'status' => 'auto_confirmed', 'confidence' => 0.95,
            'explanation' => 'antihypertensive tablets [web: rlsnet.ru]',
            'trace' => ['heading' => '3004', 'heading_name' => 'Medicaments', 'confidence' => 0.95],
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(ClassificationDecision::class, ['item' => $item])
            ->assertOk()
            ->assertSee('web-search resolver')
            ->assertSee('3004')
            ->assertSee('[web: rlsnet.ru]')
            ->assertDontSee('classified before the decision-flow feature');
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
