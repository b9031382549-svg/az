<?php

namespace Tests\Feature\Llm;

use App\Models\FinetuneAdapter;
use App\Models\GpuServer;
use App\Services\Llm\ModelUnavailable;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

// On premise the "gpu:" models live on the customer's own fixed server (LLM_BASE_URL): every
// gpu: call goes there, nothing falls back to Token Factory, and a server that cannot answer
// is ModelUnavailable (worth waiting for), never mistaken for a bad request.
class OnPremiseEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openrouter.base_url', 'https://openrouter.test/api/v1');
        config()->set('services.openrouter.api_key', 'or-key');
        config()->set('services.nebius.base_url', 'https://tokenfactory.test/v1');
        config()->set('services.nebius.api_key', 'tf-key');
        config()->set('services.llm.base_url', 'http://vllm.test:8000/v1/');
        config()->set('services.llm.api_key', 'vllm-key');
        config()->set('services.llm.base_model', 'base');
        config()->set('services.llm.tuned_model', 'xif');
    }

    /** @return array<string, mixed> */
    private function answer(): array
    {
        return [
            'model' => 'x',
            'choices' => [['message' => ['content' => 'ok']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
    }

    private function ask(string $model): void
    {
        OpenRouterClient::fromConfig()->complete([['role' => 'user', 'content' => 'hi']], ['model' => $model]);
    }

    public function test_gpu_models_go_to_the_fixed_server_under_its_own_names(): void
    {
        Http::fake(['*' => Http::response($this->answer())]);

        $this->ask('gpu:tuned');
        $this->ask('gpu:base');

        Http::assertSent(fn ($r) => $r->url() === 'http://vllm.test:8000/v1/chat/completions'
            && $r['model'] === 'xif' && $r->hasHeader('Authorization', 'Bearer vllm-key'));
        Http::assertSent(fn ($r) => $r->url() === 'http://vllm.test:8000/v1/chat/completions' && $r['model'] === 'base');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'tokenfactory.test'));
    }

    public function test_the_fixed_server_wins_over_an_active_gpu_slot(): void
    {
        $adapter = FinetuneAdapter::create(['version' => 'test-A']);
        GpuServer::query()->where('slot', 'A')->update([
            'is_active' => true, 'role' => 'serving', 'status' => GpuServer::STATUS_SERVING,
            'base_url' => 'https://gpu-a.test:8000/v1', 'api_key' => 'sk-a', 'served_adapter_id' => $adapter->id,
        ]);
        Http::fake(['*' => Http::response($this->answer())]);

        $this->ask('gpu:tuned');

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://vllm.test:8000/v1/'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'gpu-a.test'));
    }

    public function test_a_server_without_a_key_gets_no_authorization_header(): void
    {
        config()->set('services.llm.api_key', null);
        Http::fake(['*' => Http::response($this->answer())]);

        $this->ask('gpu:tuned');

        Http::assertSent(fn ($r) => ! $r->hasHeader('Authorization'));
    }

    public function test_a_server_that_cannot_answer_is_unavailable_but_a_bad_request_is_not(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => 'down'], 503)
            ->push(['error' => 'busy'], 429)
            ->push(['error' => 'no such model'], 404)
            ->push(['error' => 'context too long'], 400)]);

        foreach ([503, 429, 404] as $status) {
            try {
                $this->ask('gpu:tuned');
                $this->fail("no exception for {$status}");
            } catch (ModelUnavailable $e) {
                $this->assertStringContainsString("({$status})", $e->getMessage());
            }
        }

        // Waiting would never fix a bad request, so it stays a plain failure.
        try {
            $this->ask('gpu:tuned');
            $this->fail('no exception for 400');
        } catch (ModelUnavailable) {
            $this->fail('a 400 is not a server that is down');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(400)', $e->getMessage());
        }
    }

    public function test_an_unreachable_server_is_unavailable(): void
    {
        Http::fake(['*' => Http::failedConnection('Connection refused')]);

        $this->expectException(ModelUnavailable::class);

        $this->ask('gpu:tuned');
    }
}
