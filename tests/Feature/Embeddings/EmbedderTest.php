<?php

namespace Tests\Feature\Embeddings;

use App\Services\Embeddings\Embedder;
use App\Services\Llm\ModelUnavailable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

// One embedder, two transports: Ollama's /api/embed (our servers) or any OpenAI-compatible
// /embeddings (an on-premise vLLM / TEI / llama.cpp server). Same vectors either way, in
// input order; a server that cannot answer is ModelUnavailable.
class EmbedderTest extends TestCase
{
    private function openai(?string $key = 'e-key'): void
    {
        config()->set('services.embeddings.driver', 'openai');
        config()->set('services.embeddings.base_url', 'http://embed.test/v1/');
        config()->set('services.embeddings.api_key', $key);
        config()->set('services.embeddings.model', 'bge-ft-v3');
    }

    public function test_ollama_posts_to_api_embed(): void
    {
        config()->set('services.embeddings.driver', 'ollama');
        config()->set('services.ollama.url', 'http://ollama.test/');
        config()->set('services.ollama.embed_model', 'bge-ft-v3');
        Http::fake(['ollama.test/api/embed' => Http::response(['embeddings' => [[0.1, 0.2], [0.3, 0.4]]])]);

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], Embedder::fromConfig()->embed(['a', 'b']));
        Http::assertSent(fn ($r) => $r->url() === 'http://ollama.test/api/embed'
            && $r['model'] === 'bge-ft-v3' && $r['input'] === ['a', 'b']);
        $this->assertSame('bge-ft-v3', Embedder::configuredModel());
    }

    public function test_openai_posts_to_embeddings_and_keeps_the_input_order(): void
    {
        $this->openai();
        // The spec does not promise the order of data[]: each vector carries its index.
        Http::fake(['embed.test/v1/embeddings' => Http::response(['data' => [
            ['index' => 1, 'embedding' => [0.3, 0.4]],
            ['index' => 0, 'embedding' => [0.1, 0.2]],
        ]])]);

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], Embedder::fromConfig()->embed(['a', 'b']));
        Http::assertSent(fn ($r) => $r->url() === 'http://embed.test/v1/embeddings'
            && $r->hasHeader('Authorization', 'Bearer e-key') && $r['model'] === 'bge-ft-v3' && $r['input'] === ['a', 'b']);
    }

    public function test_openai_without_a_key_sends_no_authorization(): void
    {
        $this->openai(null);
        Http::fake(['*' => Http::response(['data' => [['index' => 0, 'embedding' => [0.1]]]])]);

        Embedder::fromConfig()->embedOne('a');

        Http::assertSent(fn ($r) => ! $r->hasHeader('Authorization'));
    }

    public function test_a_server_that_cannot_answer_is_unavailable_but_a_bad_request_is_not(): void
    {
        $this->openai();
        Http::fake(['*' => Http::sequence()->push(['error' => 'down'], 503)->push(['error' => 'bad'], 400)]);

        try {
            Embedder::fromConfig()->embedOne('a');
            $this->fail('no exception for 503');
        } catch (ModelUnavailable $e) {
            $this->assertStringContainsString('(503)', $e->getMessage());
        }

        try {
            Embedder::fromConfig()->embedOne('a');
            $this->fail('no exception for 400');
        } catch (ModelUnavailable) {
            $this->fail('a 400 is not a server that is down');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(400)', $e->getMessage());
        }
    }

    public function test_an_unreachable_server_is_unavailable(): void
    {
        $this->openai();
        Http::fake(['*' => Http::failedConnection('Connection refused')]);

        $this->expectException(ModelUnavailable::class);

        Embedder::fromConfig()->embedOne('a');
    }

    public function test_a_payload_with_the_wrong_number_of_vectors_is_refused(): void
    {
        $this->openai();
        Http::fake(['*' => Http::response(['data' => [['index' => 0, 'embedding' => [0.1]]]])]);

        $this->expectException(RuntimeException::class);

        Embedder::fromConfig()->embed(['a', 'b']);
    }
}
