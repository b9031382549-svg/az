<?php

namespace Tests\Feature\Llm;

use App\Services\Llm\OpenRouterClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenRouterClientProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openrouter.base_url', 'https://openrouter.test/api/v1');
        config()->set('services.openrouter.api_key', 'or-key');
        config()->set('services.openrouter.model', 'default/model');
        config()->set('services.openrouter.timeout', 60);
        config()->set('services.nebius.base_url', 'https://nebius.test/v1');
        config()->set('services.nebius.api_key', 'nb-key');

        Http::fake(['*' => Http::response([
            'model' => 'x',
            'choices' => [['message' => ['content' => 'ok']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ])]);
    }

    public function test_unprefixed_model_goes_to_openrouter(): void
    {
        OpenRouterClient::fromConfig()->complete([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://openrouter.test/api/v1/chat/completions')
            && $r->hasHeader('Authorization', 'Bearer or-key')
            && $r['model'] === 'default/model');
    }

    public function test_nebius_prefixed_model_routes_to_nebius_and_strips_prefix(): void
    {
        OpenRouterClient::fromConfig()->complete(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'nebius:deepseek-ai/DeepSeek-V4-Pro'],
        );

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://nebius.test/v1/chat/completions')
            && $r->hasHeader('Authorization', 'Bearer nb-key')
            // the "nebius:" prefix is stripped before the model reaches the API
            && $r['model'] === 'deepseek-ai/DeepSeek-V4-Pro');
    }

    public function test_missing_nebius_key_reports_the_right_env_var(): void
    {
        config()->set('services.nebius.api_key', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NEBIUS_API_KEY is not configured.');

        OpenRouterClient::fromConfig()->complete(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'nebius:deepseek-ai/DeepSeek-V4-Pro'],
        );
    }
}
