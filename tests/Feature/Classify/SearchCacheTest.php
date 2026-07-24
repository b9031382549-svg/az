<?php

namespace Tests\Feature\Classify;

use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Models\LlmSearchCache;
use App\Models\LlmUsage;
use App\Services\Classify\SearchResolverService;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SearchCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A real 4-digit heading the resolver validates against, so a confident answer counts.
        CatalogCode::create(['code' => '8471300000', 'name' => 'noutbuk', 'name_en' => 'laptops', 'kind' => 'good', 'chapter' => '84', 'position' => '8471', 'subposition' => '847130', 'is_active' => true]);
        config()->set('classify.search_resolver.min_confidence', 0.8);
        config()->set('classify.search_resolver.cache_enabled', true);
    }

    private function item(string $text): ClassificationItem
    {
        return ClassificationItem::create(['batch' => 't', 'source_text' => $text, 'source_hash' => 'h'.mt_rand(), 'resolution' => 'conflict']);
    }

    /** Mock the LLM to expect EXACTLY $times live calls — the cache assertion. */
    private function mockLlm(string $content, int $times): void
    {
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('complete')->times($times)->andReturn([
            'content' => $content,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            'model' => 'deepseek/deepseek-v4-flash:online',
            'annotations' => [],
        ]);
        $this->instance(OpenRouterClient::class, $llm);
    }

    public function test_confident_answer_is_cached_and_reused_without_a_second_live_call(): void
    {
        $this->mockLlm('reasoning...{"heading":"8471","kind":"good","confidence":0.95,"reason":"a laptop"}', 1); // exactly once

        $svc = app(SearchResolverService::class);
        $a = $this->item('noutbuk kompüter');
        $b = $this->item('noutbuk kompüter'); // identical name → same cache key
        $svc->resolve($a);
        $svc->resolve($b);

        $this->assertSame('ai_resolved', $a->refresh()->resolution);
        $this->assertSame('ai_resolved', $b->refresh()->resolution); // resolved from cache, no 2nd call
        $this->assertSame('8471', $b->final_code);
        $this->assertSame(1, LlmSearchCache::count());
        $this->assertTrue(
            LlmUsage::where('purpose', 'search_resolve')->where('status', 'cache')->exists(),
            'a cache hit should be logged as a zero-cost search_resolve row',
        );
    }

    public function test_low_confidence_answer_is_not_cached(): void
    {
        // Below the 0.8 gate → the web might do better next time, so never freeze it.
        $this->mockLlm('{"heading":"8471","confidence":0.4,"reason":"unsure"}', 2); // both calls live

        $svc = app(SearchResolverService::class);
        $svc->resolve($this->item('grelka'));
        $svc->resolve($this->item('grelka'));

        $this->assertSame(0, LlmSearchCache::count());
    }

    public function test_unknown_heading_is_not_cached(): void
    {
        // Confident but not a real catalog heading → not trustworthy, so not cached.
        $this->mockLlm('{"heading":"1234","confidence":0.99,"reason":"guess"}', 2);

        $svc = app(SearchResolverService::class);
        $svc->resolve($this->item('mystery'));
        $svc->resolve($this->item('mystery'));

        $this->assertSame(0, LlmSearchCache::count());
    }

    public function test_disabled_flag_bypasses_the_cache(): void
    {
        config()->set('classify.search_resolver.cache_enabled', false);
        $this->mockLlm('{"heading":"8471","confidence":0.95,"reason":"a laptop"}', 2); // no caching → two live calls

        $svc = app(SearchResolverService::class);
        $svc->resolve($this->item('noutbuk'));
        $svc->resolve($this->item('noutbuk'));

        $this->assertSame(0, LlmSearchCache::count());
    }
}
