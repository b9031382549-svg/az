<?php

namespace Tests\Feature\Classify;

use App\Models\CatalogCode;
use App\Services\Classify\CatalogRetriever;
use App\Services\Classify\Mechanisms\BrokerDescentMechanism;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BrokerDescentMechanismTest extends TestCase
{
    use RefreshDatabase;

    private function seedTree(): void
    {
        // chapter 84 -> 8471 -> {847130 (2 leaves), 847141 (1 leaf)}; chapter 85 -> 8528 -> 852872.
        $rows = [
            ['8471300000', '84', '8471', '847130', 'Hesablayıcı maşınlar:– portativ noutbuk'],
            ['8471300001', '84', '8471', '847130', 'Hesablayıcı maşınlar:– portativ noutbuk 2'],
            ['8471410000', '84', '8471', '847141', 'Hesablayıcı maşınlar:– digər blok'],
            ['8528720000', '85', '8528', '852872', 'Monitorlar və proyektorlar:– televizor'],
        ];
        foreach ($rows as [$code, $ch, $pos, $sub, $name]) {
            CatalogCode::create(['code' => $code, 'name' => $name, 'kind' => 'good', 'chapter' => $ch, 'position' => $pos, 'subposition' => $sub, 'is_active' => true]);
        }
        $this->artisan('data:build-rubricator')->assertSuccessful();
    }

    private function decideResponse(string $choice, float $confidence, bool $decisive = true): array
    {
        return $this->wrap(['criterion' => 'function', 'choice' => $choice, 'confidence' => $confidence, 'decisive' => $decisive, 'question' => '', 'reason' => 'r']);
    }

    private function leafResponse(?string $code, float $confidence): array
    {
        return $this->wrap(['code' => $code, 'confidence' => $confidence, 'reason' => 'r']);
    }

    private function wrap(array $data): array
    {
        return ['model' => 'openai/gpt-4o', 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2], 'latency_ms' => 1, 'raw' => '{}', 'data' => $data];
    }

    public function test_clean_descent_reaches_a_leaf_and_auto_confirms(): void
    {
        $this->seedTree();
        config()->set('classify.expand_query', false); // canonicalize() makes no LLM call in tests

        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->andReturn(
            $this->decideResponse('84', 0.9),          // root: chapters 84 vs 85
            $this->decideResponse('847130', 0.85),     // 8471 subs: 847130 vs 847141
            $this->leafResponse('8471300000', 0.9),    // leaf pick among 847130's 2 leaves
        );
        $this->instance(OpenRouterClient::class, $llm);

        // Broker gates auto-confirm on semantic backing (cosine of the pick);
        // sqlite has no pgvector, so stub it above the min_semantic bar.
        $retriever = Mockery::mock(CatalogRetriever::class);
        $retriever->shouldReceive('semanticSimilarity')->andReturn(0.7);
        $this->instance(CatalogRetriever::class, $retriever);

        $result = app(BrokerDescentMechanism::class)->classify('Dell Latitude noutbuk');

        $this->assertSame('8471300000', $result->matchedCode);
        $this->assertSame('good', $result->kind);
        $this->assertSame('auto_confirmed', $result->status);   // clean descent, min conf 0.85 >= 0.8
        $this->assertNotNull($result->catalogId);
        $this->assertNotEmpty($result->path);
        $this->assertContains('decided', array_column($result->path, 'by'));
    }

    public function test_over_specific_fork_choice_maps_to_the_chapter_by_prefix(): void
    {
        $this->seedTree();
        config()->set('classify.expand_query', false);

        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->andReturn(
            $this->decideResponse('8471', 0.9),        // ROOT: model answers a HEADING code, not chapter "84"
            $this->decideResponse('847130', 0.85),     // 8471 subs
            $this->leafResponse('8471300000', 0.9),
        );
        $this->instance(OpenRouterClient::class, $llm);

        $retriever = Mockery::mock(CatalogRetriever::class);
        $retriever->shouldReceive('semanticSimilarity')->andReturn(0.7);
        $this->instance(CatalogRetriever::class, $retriever);

        $result = app(BrokerDescentMechanism::class)->classify('noutbuk');

        // "8471" maps to chapter "84" by prefix → the descent continues instead of
        // discarding a correct-but-too-precise answer as undecided.
        $this->assertSame('8471300000', $result->matchedCode);
        $this->assertContains('decided', array_column($result->path, 'by'));
    }

    public function test_undecided_root_fork_abstains(): void
    {
        $this->seedTree();
        config()->set('classify.expand_query', false);

        $llm = Mockery::mock(OpenRouterClient::class);
        // Root fork is not decisive and gives no question -> no chapter established.
        $llm->shouldReceive('jsonWithUsage')->andReturn(
            $this->decideResponse('84', 0.4, decisive: false),
        );
        $this->instance(OpenRouterClient::class, $llm);

        // The retriever must NOT be consulted — a root-undecided broker abstains
        // rather than fabricate an unconstrained retrieval pick.
        $retriever = Mockery::mock(CatalogRetriever::class);
        $retriever->shouldReceive('candidates')->never();
        $this->instance(CatalogRetriever::class, $retriever);

        $result = app(BrokerDescentMechanism::class)->classify('ambiguous item');

        $this->assertNull($result->matchedCode);
        $this->assertSame('no_match', $result->status);
        $this->assertContains('abstain', array_column($result->path, 'by'));
    }
}
