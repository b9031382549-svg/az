<?php

namespace Tests\Feature\Classify;

use App\Services\Classify\CatalogRetriever;
use App\Services\Classify\ClassifierService;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

// Characterizes the decision engine that must NOT regress through the storage
// refactor: the auto_confirm AND-gate (LLM confidence >= 0.8 AND semantic_sim
// >= 0.5) and the two-tier escalation rule. Retriever + LLM are mocked.
class ClassifierServiceDecisionTest extends TestCase
{
    use RefreshDatabase; // LlmLog::record writes to llm_usage

    private function candidate(string $code, float $sim, string $kind = 'good'): object
    {
        return (object) [
            'id' => 1, 'code' => $code, 'name' => 'Test '.$code,
            'kind' => $kind, 'score' => 0.5, 'semantic_sim' => $sim,
        ];
    }

    /** @return array<string, mixed> a jsonWithUsage() response */
    private function llmResponse(?string $code, float $confidence, string $model = 'tier2-model'): array
    {
        return [
            'model' => $model,
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            'latency_ms' => 1,
            'raw' => '{}',
            'data' => ['kind' => 'good', 'code' => $code, 'confidence' => $confidence, 'reason' => 'r'],
        ];
    }

    /** @param array<int, object> $candidates */
    private function singleTierService(array $candidates, OpenRouterClient $llm): ClassifierService
    {
        // These characterize the legacy expand→retrieve→two-tier-rerank engine (still live
        // code); the vector_first path deliberately skips the LLM pick (see its own test).
        config()->set('classify.vector_first.enabled', false);
        config()->set('classify.expand_query', false);
        config()->set('classify.two_tier', false);
        config()->set('classify.auto_confirm', 0.8);
        config()->set('classify.min_semantic', 0.5);
        config()->set('services.openrouter.classify_model', 'tier2-model');

        $retriever = Mockery::mock(CatalogRetriever::class);
        $retriever->shouldReceive('candidates')->andReturn($candidates);

        return new ClassifierService($retriever, $llm);
    }

    public function test_auto_confirmed_when_confident_and_semantically_backed(): void
    {
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->andReturn($this->llmResponse('C1', 0.9));

        $r = $this->singleTierService([$this->candidate('C1', 0.7)], $llm)->classify('item');

        $this->assertSame('auto_confirmed', $r['status']);
        $this->assertSame('C1', $r['code']);
    }

    public function test_needs_review_when_confident_but_not_backed(): void
    {
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->andReturn($this->llmResponse('C1', 0.9));

        $r = $this->singleTierService([$this->candidate('C1', 0.3)], $llm)->classify('item');

        $this->assertSame('needs_review', $r['status']);
    }

    public function test_needs_review_when_backed_but_not_confident(): void
    {
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->andReturn($this->llmResponse('C1', 0.6));

        $r = $this->singleTierService([$this->candidate('C1', 0.7)], $llm)->classify('item');

        $this->assertSame('needs_review', $r['status']);
    }

    public function test_no_match_when_llm_returns_null_code(): void
    {
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->andReturn($this->llmResponse(null, 0.0));

        $r = $this->singleTierService([$this->candidate('C1', 0.7)], $llm)->classify('item');

        $this->assertSame('no_match', $r['status']);
        $this->assertNull($r['code']);
    }

    public function test_vector_first_takes_top_1_without_an_llm_pick(): void
    {
        // The vector_first path no longer LLM-re-ranks: matched_code is the nearest
        // candidate, status gates on semantic backing alone, and the LLM is never called.
        config()->set('classify.vector_first.enabled', true);
        config()->set('classify.min_semantic', 0.5);

        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldNotReceive('jsonWithUsage');

        $retriever = Mockery::mock(CatalogRetriever::class);
        $retriever->shouldReceive('semanticCandidates')->andReturn([
            $this->candidate('8471300000', 0.72),
            $this->candidate('8528720000', 0.61),
        ]);

        $r = (new ClassifierService($retriever, $llm))->classify('laptop');

        $this->assertSame('8471300000', $r['code']);        // raw top-1, no rerank
        $this->assertSame('auto_confirmed', $r['status']);  // backed: 0.72 >= 0.5
        $this->assertCount(2, $r['candidates']);            // the shortlist is preserved
    }

    public function test_vector_first_needs_review_when_top_1_not_semantically_backed(): void
    {
        config()->set('classify.vector_first.enabled', true);
        config()->set('classify.min_semantic', 0.5);

        $llm = Mockery::mock(OpenRouterClient::class);
        $retriever = Mockery::mock(CatalogRetriever::class);
        $retriever->shouldReceive('semanticCandidates')->andReturn([$this->candidate('8471300000', 0.31)]);

        $r = (new ClassifierService($retriever, $llm))->classify('laptop');

        $this->assertSame('needs_review', $r['status']); // 0.31 < 0.5
    }

    public function test_two_tier_escalates_when_tier1_not_confident(): void
    {
        config()->set('classify.vector_first.enabled', false);
        config()->set('classify.expand_query', false);
        config()->set('classify.two_tier', true);
        config()->set('classify.auto_confirm', 0.8);
        config()->set('classify.min_semantic', 0.5);
        config()->set('services.openrouter.classify_model_tier1', 'tier1-model');
        config()->set('services.openrouter.classify_model', 'tier2-model');

        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->twice()->andReturn(
            $this->llmResponse('C1', 0.6, 'tier1-model'), // weak -> escalate
            $this->llmResponse('C1', 0.95, 'tier2-model'), // strong
        );

        $retriever = Mockery::mock(CatalogRetriever::class);
        $retriever->shouldReceive('candidates')->andReturn([$this->candidate('C1', 0.7)]);

        $r = (new ClassifierService($retriever, $llm))->classify('item');

        $this->assertTrue($r['escalated']);
        $this->assertSame(2, $r['tier']);
        $this->assertSame('auto_confirmed', $r['status']);
    }
}
