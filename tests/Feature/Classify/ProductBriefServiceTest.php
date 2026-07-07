<?php

namespace Tests\Feature\Classify;

use App\Services\Classify\ProductBriefService;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProductBriefServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Test the base pass in isolation; the search escalation has its own test.
        config()->set('classify.broker.brief_search_model', '');
    }

    /** @param array<string, mixed> $data */
    private function mockLlmOnce(array $data): void
    {
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->once()->andReturn([
            'model' => 'openai/gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            'latency_ms' => 1, 'raw' => '{}', 'data' => $data,
        ]);
        $this->instance(OpenRouterClient::class, $llm);
    }

    public function test_brief_normalizes_and_caches(): void
    {
        $this->mockLlmOnce([
            'identity' => 'rubber hot-water bottle', 'purpose' => 'apply heat', 'function_class' => 'article',
            'material' => ['value' => 'rubber', 'basis' => 'typical'], 'decisive_axis' => 'material', 'confidence' => 0.8,
        ]);

        $svc = app(ProductBriefService::class);
        $brief = $svc->brief('Qrelka 2000 ml');

        $this->assertSame('rubber hot-water bottle', $brief['identity']);
        $this->assertSame('material', $brief['decisive_axis']);
        $this->assertSame('typical', $brief['material']['basis']);
        $this->assertSame('rubber', $brief['material']['value']);
        $this->assertDatabaseCount('product_briefs', 1);

        // Second call is served from the cache — jsonWithUsage was mocked ->once(),
        // so a second model hit would fail the expectation.
        $this->assertEquals($brief, $svc->brief('Qrelka 2000 ml'));
    }

    public function test_unknown_enums_are_coerced_to_safe_defaults(): void
    {
        $this->mockLlmOnce([
            'identity' => 'thing', 'function_class' => 'weird',
            'material' => ['value' => '', 'basis' => 'bogus'], 'decisive_axis' => 'nonsense', 'confidence' => 0.5,
        ]);

        $brief = app(ProductBriefService::class)->brief('x');

        $this->assertSame('other', $brief['function_class']);
        $this->assertSame('unknown', $brief['material']['basis']);
        $this->assertNull($brief['material']['value']);
        $this->assertSame('identity', $brief['decisive_axis']);
    }

    public function test_empty_identity_is_unusable_and_returns_null(): void
    {
        $this->mockLlmOnce(['identity' => '', 'confidence' => 0.1]);

        $this->assertNull(app(ProductBriefService::class)->brief('garbled ###'));
        // Cached as ok=false so the dud is not re-fetched next time.
        $this->assertDatabaseHas('product_briefs', ['ok' => false]);
    }

    public function test_low_confidence_base_brief_escalates_to_the_search_model(): void
    {
        config()->set('classify.broker.brief_model', 'base');
        config()->set('classify.broker.brief_search_model', 'searcher:online');
        config()->set('classify.broker.brief_search_below', 0.55);

        $models = [];
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->twice()->andReturnUsing(function ($messages, $opts) use (&$models) {
            $models[] = $opts['model'];
            // base pass: an unfamiliar brand, low confidence → search pass identifies it.
            $searched = $opts['model'] !== 'base';

            return [
                'model' => $opts['model'], 'usage' => ['total_tokens' => 2], 'latency_ms' => 1, 'raw' => '{}',
                'data' => ['identity' => $searched ? 'tobacco heating device' : 'GLO HAYPER', 'confidence' => $searched ? 0.9 : 0.3, 'function_class' => 'appliance'],
            ];
        });
        $this->instance(OpenRouterClient::class, $llm);

        $brief = app(ProductBriefService::class)->brief('GLO HAYPER UNIQ');

        $this->assertSame('tobacco heating device', $brief['identity']); // the searched result wins
        $this->assertContains('searcher:online', $models);
    }

    public function test_disabled_returns_null_without_calling_the_model(): void
    {
        config()->set('classify.broker.use_brief', false);

        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldNotReceive('jsonWithUsage');
        $this->instance(OpenRouterClient::class, $llm);

        $this->assertNull(app(ProductBriefService::class)->brief('anything'));
    }
}
