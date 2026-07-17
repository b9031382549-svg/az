<?php

namespace Tests\Feature\Classify;

use App\Models\CatalogCode;
use App\Services\Classify\Mechanisms\DirectLlmMechanism;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DirectLlmMechanismTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Pin the code-mode path + model so these tests are deterministic regardless of
        // the ambient .env (which sets granularity=heading and a Nebius model in this
        // deployment). The heading-mode tests below override the granularity explicitly.
        config()->set('classify.direct.granularity', 'code');
        config()->set('classify.direct.model', 'openai/gpt-oss-120b');
    }

    private function mockComplete(string $content, bool $throw = false): void
    {
        $llm = Mockery::mock(OpenRouterClient::class);
        if ($throw) {
            $llm->shouldReceive('complete')->andThrow(new RuntimeException('timed out'));
        } else {
            $llm->shouldReceive('complete')->andReturn(['content' => $content, 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2], 'model' => 'deepseek/deepseek-r1']);
        }
        $this->instance(OpenRouterClient::class, $llm);
    }

    private function seedCode(): void
    {
        CatalogCode::create(['code' => '9018390000', 'name' => 'tibbi', 'kind' => 'good', 'chapter' => '90', 'position' => '9018', 'subposition' => '901839', 'is_active' => true]);
    }

    public function test_valid_catalog_code_is_returned_and_auto_confirmed(): void
    {
        $this->seedCode();
        $this->mockComplete('reasoning here...\n{"code":"9018390000","confidence":0.9,"reason":"medical needle"}');

        $r = app(DirectLlmMechanism::class)->classify('kəpənək iynə');

        $this->assertSame('9018390000', $r->matchedCode);
        $this->assertSame('good', $r->kind);
        $this->assertSame('auto_confirmed', $r->status);
        $this->assertSame('openai/gpt-oss-120b', $r->model); // search-free direct model
    }

    public function test_captures_web_search_sources_into_the_reason(): void
    {
        $this->seedCode();
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('complete')->andReturn([
            'content' => '{"code":"9018390000","confidence":0.9,"reason":"medical needle"}',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            'model' => 'deepseek/deepseek-v4-flash',
            'annotations' => [
                ['url' => 'https://apteka-germes.com.ua/x', 'title' => 't1'],
                ['url' => 'https://www.liki.ua/y', 'title' => 't2'],
            ],
        ]);
        $this->instance(OpenRouterClient::class, $llm);

        $r = app(DirectLlmMechanism::class)->classify('kəpənək iynə');

        $this->assertStringContainsString('[web: apteka-germes.com.ua, liki.ua]', (string) $r->explanation);
    }

    public function test_low_confidence_code_needs_review(): void
    {
        $this->seedCode();
        $this->mockComplete('{"code":"9018390000","confidence":0.4,"reason":"maybe"}');

        $this->assertSame('needs_review', app(DirectLlmMechanism::class)->classify('x')->status);
    }

    public function test_code_not_in_catalog_abstains(): void
    {
        // valid-looking 10 digits whose subheading has no catalog row → hallucinated.
        $this->mockComplete('{"code":"9999999999","confidence":0.95,"reason":"x"}');

        $r = app(DirectLlmMechanism::class)->classify('x');

        $this->assertNull($r->matchedCode);
        $this->assertSame('no_match', $r->status);
    }

    public function test_snaps_recalled_code_to_a_real_subheading_code(): void
    {
        // Right subheading (401490), wrong last digits — snap to the real catalog code.
        CatalogCode::create(['code' => '4014900000', 'name' => 'rezin', 'kind' => 'good', 'chapter' => '40', 'position' => '4014', 'subposition' => '401490', 'is_active' => true]);
        $this->mockComplete('{"code":"4014901000","confidence":0.85,"reason":"rubber hot-water bottle"}');

        $r = app(DirectLlmMechanism::class)->classify('qrelka');

        $this->assertSame('4014900000', $r->matchedCode);
        $this->assertStringContainsString('recalled 4014901000 → 4014900000', (string) $r->explanation);
    }

    public function test_null_code_abstains(): void
    {
        $this->mockComplete('{"code":null,"confidence":0.1,"reason":"garbled, cannot tell"}');

        $this->assertNull(app(DirectLlmMechanism::class)->classify('Elektirov 4lük')->matchedCode);
    }

    public function test_timeout_or_error_abstains_without_throwing(): void
    {
        $this->mockComplete('', throw: true);

        $r = app(DirectLlmMechanism::class)->classify('x');

        $this->assertNull($r->matchedCode);
        $this->assertSame('no_match', $r->status);
    }

    public function test_heading_mode_returns_a_4digit_heading(): void
    {
        config()->set('classify.direct.granularity', 'heading');
        $this->seedCode(); // 9018390000 → active position 9018
        $this->mockComplete('reasoning...\n{"heading":"9018","kind":"good","confidence":0.9,"reason":"medical needle"}');

        $r = app(DirectLlmMechanism::class)->classify('kəpənək iynə');

        $this->assertSame('9018', $r->matchedCode); // heading, NOT a full 10-digit code
        $this->assertSame('good', $r->kind);
        $this->assertSame('auto_confirmed', $r->status);
    }

    public function test_heading_mode_flags_a_service(): void
    {
        config()->set('classify.direct.granularity', 'heading');
        $this->mockComplete('{"heading":null,"kind":"service","confidence":0.9,"reason":"repair work"}');

        $r = app(DirectLlmMechanism::class)->classify('kondisioner təmiri');

        $this->assertSame('99', $r->matchedCode);
        $this->assertSame('service', $r->kind);
    }

    public function test_heading_mode_abstains_on_a_nonexistent_heading(): void
    {
        config()->set('classify.direct.granularity', 'heading');
        // 9999 is not a real active catalog position → not trusted.
        $this->mockComplete('{"heading":"9999","kind":"good","confidence":0.95,"reason":"x"}');

        $r = app(DirectLlmMechanism::class)->classify('x');

        $this->assertNull($r->matchedCode);
        $this->assertSame('no_match', $r->status);
    }
}
