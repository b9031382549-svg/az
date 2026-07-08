<?php

namespace Tests\Feature\Classify;

use App\Jobs\SearchResolveJob;
use App\Models\ClassificationItem;
use App\Services\Classify\Consensus;
use App\Services\Classify\SearchResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

// Consensus dispatches the search resolver exactly once when the mechanisms diverge,
// and never reverts an item the resolver already settled.
class SearchResolveDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('classify.mechanisms.enabled', ['vector', 'broker']);
        config()->set('classify.mechanisms.shadow', []);
        config()->set('classify.search_resolver.enabled', true);
    }

    private function item(string $resolution = 'pending'): ClassificationItem
    {
        return ClassificationItem::create(['batch' => 't', 'source_text' => 'x', 'source_hash' => 'h'.mt_rand(), 'resolution' => $resolution]);
    }

    /** Two mechanisms on DIFFERENT headings → resolve() computes 'conflict'. */
    private function divergent(): ClassificationItem
    {
        $item = $this->item();
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '1111111111', 'status' => 'auto_confirmed', 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => '2222222222', 'status' => 'auto_confirmed', 'kind' => 'good']);

        return $item;
    }

    public function test_conflict_claims_and_dispatches_the_search_resolver(): void
    {
        Queue::fake();
        $item = $this->divergent();

        app(Consensus::class)->finalize($item);

        $this->assertSame('conflict', $item->refresh()->resolution);
        $this->assertNotNull($item->search_resolved_at);
        Queue::assertPushed(SearchResolveJob::class);
    }

    public function test_disabled_does_not_dispatch(): void
    {
        Queue::fake();
        config()->set('classify.search_resolver.enabled', false);
        $item = $this->divergent();

        app(Consensus::class)->finalize($item);

        Queue::assertNotPushed(SearchResolveJob::class);
        $this->assertNull($item->refresh()->search_resolved_at);
    }

    public function test_dispatches_only_once_across_repeated_finalize(): void
    {
        Queue::fake();
        $item = $this->divergent();

        app(Consensus::class)->finalize($item); // mechanism completion
        app(Consensus::class)->finalize($item); // a later finalize (retry/failed path)

        Queue::assertPushed(SearchResolveJob::class, 1);
    }

    public function test_agreed_item_does_not_dispatch(): void
    {
        Queue::fake();
        $item = $this->item();
        // Same heading (1104) → agreed, no conflict.
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '1104100000', 'status' => 'auto_confirmed', 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => '1104900000', 'status' => 'auto_confirmed', 'kind' => 'good']);

        app(Consensus::class)->finalize($item);

        $this->assertSame('agreed', $item->refresh()->resolution);
        Queue::assertNotPushed(SearchResolveJob::class);
    }

    public function test_finalize_never_reverts_a_search_resolved_item(): void
    {
        Queue::fake();
        $item = $this->divergent();
        // Simulate the resolver having already settled it.
        $item->update(['search_resolved_at' => now(), 'resolution' => 'ai_resolved', 'final_code' => '8471']);

        app(Consensus::class)->finalize($item); // a late mechanism failed() path

        $item->refresh();
        $this->assertSame('ai_resolved', $item->resolution); // NOT recomputed back to conflict
        $this->assertSame('8471', $item->final_code);
    }

    public function test_job_is_a_noop_when_disabled(): void
    {
        config()->set('classify.search_resolver.enabled', false);
        $resolver = Mockery::mock(SearchResolverService::class);
        $resolver->shouldNotReceive('resolve');

        (new SearchResolveJob($this->divergent()->id))->handle($resolver);
    }

    public function test_job_skips_when_no_longer_a_conflict(): void
    {
        $item = $this->item('confirmed'); // a human decided while queued
        $resolver = Mockery::mock(SearchResolverService::class);
        $resolver->shouldNotReceive('resolve');

        (new SearchResolveJob($item->id))->handle($resolver);
    }

    public function test_job_runs_the_resolver_on_a_conflict(): void
    {
        $item = $this->item('conflict');
        $resolver = Mockery::mock(SearchResolverService::class);
        $resolver->shouldReceive('resolve')->once()->with(Mockery::on(fn ($i) => $i->id === $item->id));

        (new SearchResolveJob($item->id))->handle($resolver);
    }

    public function test_reaper_redispatches_an_orphaned_claim(): void
    {
        Queue::fake();
        $item = $this->item('conflict');
        $item->update(['search_resolved_at' => now()->subMinutes(30)]); // claimed long ago, no 'search' trace

        $this->artisan('classify:reap-search-resolves')->assertSuccessful();

        Queue::assertPushed(SearchResolveJob::class);
    }

    public function test_reaper_ignores_an_item_that_already_ran(): void
    {
        Queue::fake();
        $item = $this->item('conflict');
        $item->update(['search_resolved_at' => now()->subMinutes(30)]);
        $item->results()->create(['mechanism' => 'search', 'matched_code' => null, 'status' => 'needs_review']); // ran, stayed conflict

        $this->artisan('classify:reap-search-resolves')->assertSuccessful();

        Queue::assertNotPushed(SearchResolveJob::class);
    }

    public function test_reaper_ignores_a_recent_claim_still_in_flight(): void
    {
        Queue::fake();
        $item = $this->item('conflict');
        $item->update(['search_resolved_at' => now()->subMinute()]);

        $this->artisan('classify:reap-search-resolves')->assertSuccessful();

        Queue::assertNotPushed(SearchResolveJob::class);
    }

    public function test_reaper_is_a_noop_when_disabled(): void
    {
        Queue::fake();
        config()->set('classify.search_resolver.enabled', false);
        $item = $this->item('conflict');
        $item->update(['search_resolved_at' => now()->subMinutes(30)]);

        $this->artisan('classify:reap-search-resolves')->assertSuccessful();

        Queue::assertNotPushed(SearchResolveJob::class);
    }
}
