<?php

namespace Tests\Feature\Api;

use App\Models\FinetuneAdapter;
use App\Models\GpuServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Mockery;
use Tests\TestCase;

// GET /api/health-check: any valid token; 200 "Health check passed" when every part is up,
// else 500 naming each part that is not. Only liveness probes — nothing is classified,
// and an LLM provider is only asked for its model list.
class HealthCheckApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = ['Authorization' => 'Bearer '.User::factory()->create()->createToken('monitor', [])->plainTextToken];

        config()->set('classify.sorter.url', 'http://sorter.test');
        config()->set('services.ollama.url', 'http://ollama.test');
        config()->set('services.ollama.embed_model', 'bge-ft-v3');
        config()->set('classify.mechanisms.enabled', ['vector', 'direct']);
        config()->set('classify.direct.model', 'gpu:tuned');
        config()->set('classify.flow.ensemble_resolver', true);
        config()->set('classify.flow.ensemble.model', 'deepseek/deepseek-v4-flash');
        config()->set('classify.flow.ensemble.understand_model', 'deepseek/deepseek-v4-flash:online');
        config()->set('classify.search_resolver.enabled', true);
        config()->set('classify.search_resolver.model', 'deepseek/deepseek-v4-flash:online');
        config()->set('services.openrouter.base_url', 'https://openrouter.test/api/v1');
        config()->set('services.openrouter.api_key', 'or-key');
        config()->set('services.nebius.base_url', 'https://tokenfactory.test/v1');
        config()->set('services.nebius.api_key', 'tf-key');
        config()->set('services.nebius.fallback_model', 'deepseek-ai/DeepSeek-V4-Flash-0731');

        Redis::shouldReceive('connection')->andReturn(Mockery::mock(['ping' => true]));
        $this->workers([(object) ['status' => 'running']]);
    }

    /** @param  array<int, object>  $masters */
    private function workers(array $masters): void
    {
        $this->mock(MasterSupervisorRepository::class, fn ($m) => $m->shouldReceive('all')->andReturn($masters));
    }

    /** @param  array<string, mixed>  $override */
    private function services(array $override = []): void
    {
        Http::fake($override + [
            'sorter.test/health' => Http::response(['ok' => true, 'model' => 'sorter-v1']),
            'ollama.test/api/tags' => Http::response(['models' => [['name' => 'bge-ft-v3:latest']]]),
            'tokenfactory.test/v1/models' => Http::response(['data' => [['id' => 'deepseek-ai/DeepSeek-V4-Flash-0731']]]),
            'openrouter.test/api/v1/models' => Http::response(['data' => [['id' => 'deepseek/deepseek-v4-flash']]]),
        ]);
    }

    public function test_needs_a_token(): void
    {
        $this->get('/api/health-check', ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_passes_when_every_part_is_up(): void
    {
        $this->services();

        $response = $this->get('/api/health-check', $this->auth);

        $response->assertOk()->assertSeeText('Health check passed', false);
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        // Liveness only: the sorter is never asked to classify, the embedder never to embed.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/classify') || str_contains($r->url(), '/api/embed'));
        // gpu:tuned with no GPU slot up is served by the Token Factory fallback — that list is checked.
        Http::assertSent(fn ($r) => $r->url() === 'https://tokenfactory.test/v1/models' && $r->hasHeader('Authorization', 'Bearer tf-key'));
    }

    public function test_names_every_part_that_is_down(): void
    {
        $this->workers([]);
        $this->services([
            'sorter.test/health' => Http::response('down', 503),
            'openrouter.test/api/v1/models' => Http::response(['data' => [['id' => 'other/model']]]),
        ]);

        $this->get('/api/health-check', $this->auth)
            ->assertStatus(500)
            ->assertSeeText('Health check failed', false)
            ->assertSeeText('workers: no queue workers are running', false)
            ->assertSeeText('sorter:', false)
            ->assertSeeText('llm: OpenRouter does not offer deepseek/deepseek-v4-flash', false)
            ->assertDontSeeText('embedder:', false);
    }

    public function test_a_missing_embedding_model_and_paused_workers_fail(): void
    {
        $this->workers([(object) ['status' => 'paused']]);
        $this->services(['ollama.test/api/tags' => Http::response(['models' => [['name' => 'bge-m3:latest']]])]);

        $this->get('/api/health-check', $this->auth)
            ->assertStatus(500)
            ->assertSeeText('workers: the queue workers are paused', false)
            ->assertSeeText('embedder: Ollama does not have the embedding model bge-ft-v3', false);
    }

    public function test_a_retired_direct_model_fails(): void
    {
        $this->services(['tokenfactory.test/v1/models' => Http::response(['data' => [['id' => 'nvidia/Nemotron-3_5-Lightning']]])]);

        $this->get('/api/health-check', $this->auth)
            ->assertStatus(500)
            ->assertSeeText('llm: TokenFactory (fallback, base) does not offer deepseek-ai/DeepSeek-V4-Flash-0731', false);
    }

    public function test_checks_the_active_gpu_slot_without_keeping_it_awake(): void
    {
        $adapter = FinetuneAdapter::create(['version' => 'test-a']);
        GpuServer::query()->where('slot', 'A')->update([
            'is_active' => true, 'role' => 'serving', 'status' => GpuServer::STATUS_SERVING,
            'base_url' => 'https://gpu-a.test:8000/v1', 'api_key' => 'sk-a', 'served_adapter_id' => $adapter->id,
            'last_request_at' => null,
        ]);
        $this->services(['gpu-a.test:8000/v1/models' => Http::response(['data' => [['id' => 'base'], ['id' => 'xif']]])]);

        $this->get('/api/health-check', $this->auth)->assertOk();

        Http::assertSent(fn ($r) => $r->url() === 'https://gpu-a.test:8000/v1/models' && $r->hasHeader('Authorization', 'Bearer sk-a'));
        $this->assertNull(GpuServer::active()->last_request_at); // the idle-autostop clock is untouched
    }

    public function test_reuses_a_provider_model_list_for_a_few_minutes(): void
    {
        $this->services();

        $this->get('/api/health-check', $this->auth)->assertOk();
        $this->get('/api/health-check', $this->auth)->assertOk();

        Http::assertSentCount(2 + 2 + 2); // sorter + Ollama twice; each provider's model list once
    }
}
