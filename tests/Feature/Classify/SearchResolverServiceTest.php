<?php

namespace Tests\Feature\Classify;

use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Services\Classify\SearchResolverService;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SearchResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A real 4-digit heading the resolver can validate against (position is indexed).
        CatalogCode::create(['code' => '8471300000', 'name' => 'noutbuk', 'name_en' => 'laptops', 'kind' => 'good', 'chapter' => '84', 'position' => '8471', 'subposition' => '847130', 'is_active' => true]);
        config()->set('classify.search_resolver.min_confidence', 0.8);
    }

    private function mockLlm(string $content, array $annotations = []): void
    {
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('complete')->andReturn([
            'content' => $content,
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            'model' => 'deepseek/deepseek-v4-flash:online',
            'annotations' => $annotations,
        ]);
        $this->instance(OpenRouterClient::class, $llm);
    }

    private function conflictItem(): ClassificationItem
    {
        return ClassificationItem::create(['batch' => 't', 'source_text' => 'noutbuk kompüter', 'source_hash' => 'h'.mt_rand(), 'resolution' => 'conflict']);
    }

    public function test_confident_real_heading_resolves_at_4_digits(): void
    {
        $item = $this->conflictItem();
        $this->mockLlm('reasoning...{"heading":"8471","kind":"good","confidence":0.95,"reason":"a laptop"}');

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('ai_resolved', $item->resolution);
        $this->assertSame('8471', $item->final_code);        // 4-digit heading only
        $this->assertNull($item->final_catalog_id);
        $this->assertSame('good', $item->kind);

        $trace = $item->results()->where('mechanism', 'search')->first();
        $this->assertSame('8471', $trace->matched_code);
        $this->assertSame('auto_confirmed', $trace->status);
        $this->assertSame(0.95, $trace->confidence);
    }

    public function test_service_resolves_at_99(): void
    {
        $item = $this->conflictItem();
        $this->mockLlm('{"heading":"99","kind":"service","confidence":0.9,"reason":"a repair"}');

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('ai_resolved', $item->resolution);
        $this->assertSame('99', $item->final_code);
        $this->assertSame('service', $item->kind);
    }

    public function test_low_confidence_stays_conflict_for_a_human(): void
    {
        $item = $this->conflictItem();
        $this->mockLlm('{"heading":"8471","confidence":0.4,"reason":"unsure"}');

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('conflict', $item->resolution);    // handed to a human
        $this->assertNull($item->final_code);
        // …but the search attempt is recorded so the reviewer sees it.
        $this->assertSame('needs_review', $item->results()->where('mechanism', 'search')->first()->status);
    }

    public function test_unknown_heading_stays_conflict(): void
    {
        $item = $this->conflictItem();
        // 1234 is confident but not a real heading in the catalog → cannot be trusted.
        $this->mockLlm('{"heading":"1234","confidence":0.99,"reason":"guess"}');

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('conflict', $item->resolution);
        $this->assertNull($item->results()->where('mechanism', 'search')->first()->matched_code);
    }

    public function test_llm_failure_stays_conflict_and_records_the_attempt(): void
    {
        $item = $this->conflictItem();
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('complete')->andThrow(new RuntimeException('timed out'));
        $this->instance(OpenRouterClient::class, $llm);

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('conflict', $item->resolution);
        $this->assertSame('no_match', $item->results()->where('mechanism', 'search')->first()->status);
    }

    public function test_never_clobbers_a_human_decision(): void
    {
        $item = $this->conflictItem();
        $item->update(['resolution' => 'confirmed', 'final_code' => '6215200000']); // human already decided
        $this->mockLlm('{"heading":"8471","confidence":0.99,"reason":"laptop"}');

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('confirmed', $item->resolution);   // untouched
        $this->assertSame('6215200000', $item->final_code);
    }

    public function test_out_of_scale_confidence_is_not_trusted(): void
    {
        $item = $this->conflictItem();
        // A model returning 55 (or "high") must NOT bypass the 0.8 gate and auto-resolve.
        $this->mockLlm('{"heading":"8471","confidence":55,"reason":"broken scale"}');

        app(SearchResolverService::class)->resolve($item);

        $this->assertSame('conflict', $item->refresh()->resolution);
    }

    public function test_web_citations_are_folded_into_the_reason(): void
    {
        $item = $this->conflictItem();
        $this->mockLlm(
            '{"heading":"8471","confidence":0.95,"reason":"a laptop"}',
            [['url' => 'https://ru.wikipedia.org/wiki/x', 'title' => 't']],
        );

        app(SearchResolverService::class)->resolve($item);

        $this->assertStringContainsString('[web: ru.wikipedia.org]', (string) $item->results()->where('mechanism', 'search')->first()->explanation);
    }
}
