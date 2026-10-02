<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// GET /api/version: any valid token; our release version + the models the chain is configured
// to use right now (read from config, so a host that swaps an LLM sees it here).
class VersionApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function auth(array $abilities = [ApiAbilities::RESULTS]): array
    {
        return ['Authorization' => 'Bearer '.User::factory()->create()->createToken('t', $abilities)->plainTextToken];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('api.version', '1.0');
        config()->set('api.model', ['version' => '1.2.3', 'trained_at' => '2026-10-01']);
        config()->set('classify.sorter.url', 'http://sorter:8000');
        config()->set('classify.sorter.model', 'sorter-v1');
        config()->set('services.ollama.embed_model', 'bge-ft-v3');
        config()->set('classify.direct.model', 'gpu:tuned');
        config()->set('services.nebius.fallback_model', 'deepseek-ai/DeepSeek-V4-Flash-0731');
        config()->set('classify.search_resolver.enabled', true);
        config()->set('classify.search_resolver.model', 'deepseek/deepseek-v4-flash:online');
    }

    public function test_needs_a_token(): void
    {
        $this->getJson('/api/version')->assertStatus(401);
    }

    public function test_reports_the_release_and_every_model_in_use(): void
    {
        $this->getJson('/api/version', $this->auth())
            ->assertOk()
            ->assertExactJson([
                'model_version' => '1.2.3',
                'trained_at' => '2026-10-01',
                'api_version' => '1.0',
                'components' => [
                    'sorter' => 'sorter-v1',
                    'embedder' => 'bge-ft-v3',
                    'direct' => 'gpu:tuned',
                    'direct_fallback' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
                    'web_search' => 'deepseek/deepseek-v4-flash:online',
                ],
            ]);
    }

    public function test_any_token_works_even_without_abilities(): void
    {
        $this->getJson('/api/version', $this->auth([]))->assertOk();
    }

    public function test_parts_that_are_off_are_not_listed(): void
    {
        config()->set('classify.sorter.url', '');
        config()->set('classify.direct.model', 'openai/gpt-oss-120b');
        config()->set('classify.search_resolver.enabled', false);

        $this->getJson('/api/version', $this->auth())
            ->assertOk()
            ->assertExactJson([
                'model_version' => '1.2.3',
                'trained_at' => '2026-10-01',
                'api_version' => '1.0',
                'components' => ['embedder' => 'bge-ft-v3', 'direct' => 'openai/gpt-oss-120b'],
            ]);
    }
}
