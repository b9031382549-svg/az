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

    protected function setUp(): void
    {
        parent::setUp();
        // Pin the full-code descent path so these tests don't inherit an ambient
        // .env answer_granularity=heading. The heading-mode test overrides it.
        config()->set('classify.broker.answer_granularity', 'code');
    }

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

    /** @param array<string, mixed> $overrides */
    private function briefResponse(array $overrides = []): array
    {
        return $this->wrap(array_merge([
            'identity' => 'test article', 'purpose' => 'p', 'function_class' => 'article',
            'material' => ['value' => 'rubber', 'basis' => 'typical'],
            'decisive_axis' => 'material', 'confidence' => 0.8,
        ], $overrides));
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
        config()->set('classify.broker.use_brief', false); // this suite tests the descent, not the brief

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
        config()->set('classify.broker.use_brief', false);

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

    public function test_assumption_gate_forces_review_when_decisive_material_is_not_stated(): void
    {
        $this->seedTree();
        config()->set('classify.expand_query', false);
        config()->set('classify.broker.use_brief', true);

        // A clean, confident descent that WOULD auto-confirm (min conf 0.85 >= 0.8,
        // semantic backing stubbed above the bar). The brief runs first and reports
        // the classification hinges on a material the text never stated
        // (decisive_axis=material, basis=typical) → the gate must force review.
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->andReturn(
            $this->briefResponse(['decisive_axis' => 'material', 'material' => ['value' => 'rubber', 'basis' => 'typical']]),
            $this->decideResponse('84', 0.9),
            $this->decideResponse('847130', 0.9),
            $this->leafResponse('8471300000', 0.9),
        );
        $this->instance(OpenRouterClient::class, $llm);

        $retriever = Mockery::mock(CatalogRetriever::class);
        $retriever->shouldReceive('semanticSimilarity')->andReturn(0.7); // backing clears min_semantic
        $this->instance(CatalogRetriever::class, $retriever);

        $result = app(BrokerDescentMechanism::class)->classify('Qrelka 2000 ml');

        $this->assertSame('8471300000', $result->matchedCode);   // still descends and picks the code
        $this->assertSame('needs_review', $result->status);       // but is NOT auto-confirmed
        $this->assertNotEmpty($result->trace['gate']['review_forced'] ?? null);
        $this->assertSame('material', $result->trace['brief']['decisive_axis'] ?? null);
    }

    public function test_stated_material_still_auto_confirms(): void
    {
        $this->seedTree();
        config()->set('classify.expand_query', false);
        config()->set('classify.broker.use_brief', true);

        // Same clean descent, but the brief says the deciding material WAS stated —
        // the assumption gate does not fire, so a confident backed pick auto-confirms.
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->andReturn(
            $this->briefResponse(['decisive_axis' => 'material', 'material' => ['value' => 'rubber', 'basis' => 'stated']]),
            $this->decideResponse('84', 0.9),
            $this->decideResponse('847130', 0.9),
            $this->leafResponse('8471300000', 0.9),
        );
        $this->instance(OpenRouterClient::class, $llm);

        $retriever = Mockery::mock(CatalogRetriever::class);
        $retriever->shouldReceive('semanticSimilarity')->andReturn(0.7);
        $this->instance(CatalogRetriever::class, $retriever);

        $result = app(BrokerDescentMechanism::class)->classify('Rezin qrelka 2000 ml');

        $this->assertSame('auto_confirmed', $result->status);
        $this->assertNull($result->trace['gate']['review_forced'] ?? null);
    }

    public function test_heading_mode_stops_at_the_4digit_heading(): void
    {
        $this->seedTree();
        config()->set('classify.expand_query', false);
        config()->set('classify.broker.use_brief', false);
        config()->set('classify.broker.answer_granularity', 'heading');

        // Same descent as the clean-descent test, but NO leaf-pick response is given:
        // heading mode must stop at the heading before ever calling leafPick().
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->andReturn(
            $this->decideResponse('84', 0.9),
            $this->decideResponse('847130', 0.85),
        );
        $this->instance(OpenRouterClient::class, $llm);

        $result = app(BrokerDescentMechanism::class)->classify('noutbuk');

        $this->assertSame('8471', $result->matchedCode); // 4-digit heading, not a full code
        $this->assertNull($result->catalogId);
        $this->assertSame('good', $result->kind);
        $this->assertContains('heading-stop', array_column($result->path, 'by'));
    }

    public function test_undecided_root_fork_abstains(): void
    {
        $this->seedTree();
        config()->set('classify.expand_query', false);
        config()->set('classify.broker.use_brief', false);

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
