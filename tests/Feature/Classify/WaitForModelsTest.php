<?php

namespace Tests\Feature\Classify;

use App\Jobs\ClassifyMechanismJob;
use App\Models\ClassificationItem;
use App\Services\Classify\CatalogRetriever;
use App\Services\Classify\ClassifierService;
use App\Services\Classify\Consensus;
use App\Services\Classify\Mechanisms\DirectLlmMechanism;
use App\Services\Classify\Mechanisms\MechanismRegistry;
use App\Services\Llm\ModelUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// On premise the GPU and the embedding server are required. With classify.wait_for_models
// on, a mechanism whose server cannot answer makes its job wait and retry instead of storing
// an abstaining vote; off (our servers), the vote abstains exactly as before.
class WaitForModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // whatever the consensus would queue next stays out of these tests
        config()->set('services.llm.base_url', 'http://vllm.test:8000/v1');
        config()->set('services.llm.api_key', null);
        config()->set('classify.direct.model', 'gpu:tuned');
        config()->set('classify.direct.granularity', 'heading');
        config()->set('classify.vector_first.enabled', true);
        config()->set('services.embeddings.driver', 'openai');
        config()->set('services.embeddings.base_url', 'http://embed.test/v1');
        config()->set('services.embeddings.model', 'bge-ft-v3');
        Http::fake(['*' => Http::response(['error' => 'down'], 503)]); // both servers are down
    }

    private function item(): ClassificationItem
    {
        return ClassificationItem::create([
            'batch' => 'b', 'source_text' => 'Şpris 5 ml', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'pending',
        ]);
    }

    public function test_without_waiting_direct_abstains_as_before(): void
    {
        config()->set('classify.wait_for_models.enabled', false);

        $this->assertSame('no_match', app(DirectLlmMechanism::class)->classify('Şpris 5 ml')->status);
    }

    public function test_waiting_direct_reports_the_down_server(): void
    {
        config()->set('classify.wait_for_models.enabled', true);

        $this->expectException(ModelUnavailable::class);

        app(DirectLlmMechanism::class)->classify('Şpris 5 ml');
    }

    public function test_the_vector_path_returns_an_error_without_waiting_and_raises_with_it(): void
    {
        // The real retriever only embeds on PostgreSQL; here it stands for an embedding
        // server that is down.
        config()->set('classify.vector_first.heading_diverse', false);
        config()->set('classify.vector_first.lexical', 0);
        $this->mock(CatalogRetriever::class, fn ($m) => $m->shouldReceive('semanticCandidates')
            ->andThrow(new ModelUnavailable('Embedding request failed (503): down')));

        config()->set('classify.wait_for_models.enabled', false);
        $this->assertSame('error', app(ClassifierService::class)->classify('Şpris 5 ml')['status']);

        config()->set('classify.wait_for_models.enabled', true);
        $this->expectException(ModelUnavailable::class);
        app(ClassifierService::class)->classify('Şpris 5 ml');
    }

    public function test_the_job_waits_for_a_down_server_instead_of_storing_a_vote(): void
    {
        config()->set('classify.wait_for_models.enabled', true);
        config()->set('classify.wait_for_models.hours', 24);
        $item = $this->item();

        $job = (new ClassifyMechanismJob($item->id, 'direct'))->withFakeQueueInteractions();
        $job->handle(app(MechanismRegistry::class), app(Consensus::class));

        $job->assertReleased(delay: 30);
        $this->assertFalse($item->results()->exists()); // still pending: nothing abstained
        $this->assertGreaterThan(now()->addHours(23)->getTimestamp(), $job->retryUntil()->getTimestamp());
    }

    public function test_without_waiting_the_job_stores_the_abstaining_vote_as_before(): void
    {
        config()->set('classify.wait_for_models.enabled', false);
        $item = $this->item();

        $job = (new ClassifyMechanismJob($item->id, 'direct'))->withFakeQueueInteractions();
        $job->handle(app(MechanismRegistry::class), app(Consensus::class));

        $job->assertNotReleased();
        $this->assertSame('no_match', $item->results()->where('mechanism', 'direct')->value('status'));
        $this->assertNull($job->retryUntil());
    }
}
