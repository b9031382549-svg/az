<?php

namespace Tests\Feature\Llm;

use App\Models\FinetuneAdapter;
use App\Models\GpuServer;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// The "gpu:" logical models resolve to the active GPU server, or fall back to Token
// Factory (base model) when none is active. This is the single point of entry the whole
// system routes through; flipping is_active is the zero-downtime switch.
class GpuEndpointResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openrouter.base_url', 'https://openrouter.test/api/v1');
        config()->set('services.openrouter.api_key', 'or-key');
        config()->set('services.openrouter.model', 'default/model');
        config()->set('services.openrouter.timeout', 60);
        config()->set('services.nebius.base_url', 'https://tokenfactory.test/v1');
        config()->set('services.nebius.api_key', 'tf-key');
        config()->set('services.nebius.fallback_model', 'meta-llama/Llama-3.3-70B-Instruct');

        Http::fake(['*' => Http::response([
            'model' => 'x',
            'choices' => [['message' => ['content' => 'ok']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ])]);
    }

    public function test_gpu_model_falls_back_to_token_factory_base_when_no_server_active(): void
    {
        // The two seeded slots exist but neither is active.
        $this->assertNull(GpuServer::active());

        OpenRouterClient::fromConfig()->complete(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'gpu:tuned'],
        );

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://tokenfactory.test/v1/chat/completions')
            && $r->hasHeader('Authorization', 'Bearer tf-key')
            // gpu:tuned degrades to the base model on the fallback (no adapter there).
            && $r['model'] === 'meta-llama/Llama-3.3-70B-Instruct');
    }

    public function test_gpu_tuned_routes_to_active_server_and_serves_the_adapter(): void
    {
        $this->activateServer('A', 'https://gpu-a.test:8000/v1', 'sk-a');

        OpenRouterClient::fromConfig()->complete(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'gpu:tuned'],
        );

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://gpu-a.test:8000/v1/chat/completions')
            && $r->hasHeader('Authorization', 'Bearer sk-a')
            // tuned → the vLLM-served adapter name.
            && $r['model'] === 'xif');
    }

    public function test_gpu_tuned_degrades_to_base_on_a_base_only_serving_slot(): void
    {
        // Slot is serving but has NO adapter deployed (base-only) → vLLM serves no `xif`,
        // so gpu:tuned must degrade to the GPU's `base` model, not request a missing one.
        GpuServer::query()->where('slot', 'A')->update([
            'is_active' => true,
            'role' => 'serving',
            'status' => GpuServer::STATUS_SERVING,
            'base_url' => 'https://gpu-a.test:8000/v1',
            'api_key' => 'sk-a',
            'served_adapter_id' => null,
        ]);

        OpenRouterClient::fromConfig()->complete(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'gpu:tuned'],
        );

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://gpu-a.test:8000/v1/chat/completions')
            && $r->hasHeader('Authorization', 'Bearer sk-a')
            && $r['model'] === 'base');
    }

    public function test_gpu_base_routes_to_active_server_base_model(): void
    {
        $this->activateServer('B', 'https://gpu-b.test:8000/v1', 'sk-b');

        OpenRouterClient::fromConfig()->complete(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'gpu:base'],
        );

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://gpu-b.test:8000/v1/chat/completions')
            && $r->hasHeader('Authorization', 'Bearer sk-b')
            && $r['model'] === 'base');
    }

    public function test_switching_active_server_reroutes_live_without_new_client(): void
    {
        $client = OpenRouterClient::fromConfig();
        $this->activateServer('A', 'https://gpu-a.test:8000/v1', 'sk-a');

        $client->complete([['role' => 'user', 'content' => 'hi']], ['model' => 'gpu:base']);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://gpu-a.test:8000/v1/chat/completions'));

        // Flip active A → B; the SAME (singleton-like) client must reroute on the next call.
        GpuServer::query()->update(['is_active' => false]);
        $this->activateServer('B', 'https://gpu-b.test:8000/v1', 'sk-b');

        $client->complete([['role' => 'user', 'content' => 'hi']], ['model' => 'gpu:base']);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://gpu-b.test:8000/v1/chat/completions')
            && $r->hasHeader('Authorization', 'Bearer sk-b'));
    }

    public function test_an_active_but_not_yet_serving_server_still_falls_back(): void
    {
        // Provisioned but still booting (status != serving) → must NOT take traffic yet.
        GpuServer::query()->where('slot', 'A')->update([
            'is_active' => true,
            'status' => GpuServer::STATUS_BOOTING,
            'base_url' => 'https://gpu-a.test:8000/v1',
            'api_key' => 'sk-a',
        ]);

        OpenRouterClient::fromConfig()->complete(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'gpu:base'],
        );

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://tokenfactory.test/v1/chat/completions'));
    }

    private function activateServer(string $slot, string $baseUrl, string $key): void
    {
        $adapter = FinetuneAdapter::create(['version' => 'test-'.$slot]);
        GpuServer::query()->where('slot', $slot)->update([
            'is_active' => true,
            'role' => 'serving',
            'status' => GpuServer::STATUS_SERVING,
            'base_url' => $baseUrl,
            'api_key' => $key,
            'served_adapter_id' => $adapter->id,
        ]);
    }
}
