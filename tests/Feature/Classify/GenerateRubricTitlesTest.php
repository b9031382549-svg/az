<?php

namespace Tests\Feature\Classify;

use App\Models\CatalogCode;
use App\Models\RubricatorNode;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GenerateRubricTitlesTest extends TestCase
{
    use RefreshDatabase;

    public function test_fills_untitled_node_from_its_sample_leaves(): void
    {
        CatalogCode::create(['code' => '9946111100', 'name' => 'Diri heyvanların topdansatışı', 'kind' => 'service', 'position' => '9946', 'subposition' => '994611', 'is_active' => true]);
        $node = RubricatorNode::create(['code' => '9946', 'level' => 2, 'kind' => 'service', 'title' => null, 'is_active' => true]);

        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->once()->andReturn([
            'model' => 'm', 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            'latency_ms' => 1, 'raw' => '{}', 'data' => ['title' => 'Topdansatış xidmətləri'],
        ]);
        $this->instance(OpenRouterClient::class, $llm);

        $this->artisan('rubricator:generate-titles')->assertSuccessful();

        $this->assertSame('Topdansatış xidmətləri', $node->fresh()->title);
    }

    public function test_skips_when_nothing_untitled(): void
    {
        RubricatorNode::create(['code' => '84', 'level' => 1, 'kind' => 'good', 'title' => 'Machinery', 'is_active' => true]);

        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldNotReceive('jsonWithUsage');
        $this->instance(OpenRouterClient::class, $llm);

        $this->artisan('rubricator:generate-titles')->assertSuccessful();
    }
}
