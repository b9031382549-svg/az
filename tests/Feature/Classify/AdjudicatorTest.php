<?php

namespace Tests\Feature\Classify;

use App\Jobs\AdjudicateItemJob;
use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Services\Classify\AdjudicatorService;
use App\Services\Classify\Consensus;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class AdjudicatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CatalogCode::create(['code' => '9018390000', 'name' => 'tibbi iynə', 'name_en' => 'medical needles', 'kind' => 'good', 'chapter' => '90', 'position' => '9018', 'subposition' => '901839', 'is_active' => true]);
        CatalogCode::create(['code' => '6215200000', 'name' => 'qalstuk', 'name_en' => 'neckties', 'kind' => 'good', 'chapter' => '62', 'position' => '6215', 'subposition' => '621520', 'is_active' => true]);
    }

    private function divergentItem(string $resolution = 'conflict'): ClassificationItem
    {
        $item = ClassificationItem::create(['batch' => 't', 'source_text' => 'Kəpənək iynə', 'source_hash' => 'h1', 'resolution' => $resolution]);
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => '9018390000', 'kind' => 'good', 'status' => 'needs_review', 'confidence' => 0.9, 'candidates' => [['code' => '9018390000']], 'trace' => ['brief' => ['identity' => 'butterfly infusion needle']]]);
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '6215200000', 'kind' => 'good', 'status' => 'needs_review', 'confidence' => 0.9, 'candidates' => [['code' => '6215200000']]]);

        return $item;
    }

    private function verdict(string $v, ?string $code, float $conf = 0.95): string
    {
        $json = json_encode(['verdict' => $v, 'winning_code' => $code, 'winning_kind' => 'good', 'confidence' => $conf, 'which' => 'broker', 'rule_basis' => 'card COVERS needles', 'reason' => 'r']);

        return "The item is a needle, chapter 90.\n===VERDICT===\n{$json}";
    }

    /** @param array<int, string> $contents one per judge call, in order */
    private function mockJudge(array $contents): void
    {
        $responses = array_map(fn ($c) => ['content' => $c, 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2], 'model' => 'openai/gpt-oss-120b'], $contents);
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('complete')->andReturn(...$responses);
        $this->instance(OpenRouterClient::class, $llm);
    }

    public function test_stable_on_list_verdict_resolves(): void
    {
        $item = $this->divergentItem();
        $this->mockJudge([$this->verdict('resolved', '9018390000'), $this->verdict('resolved', '9018390000')]);

        $adj = app(AdjudicatorService::class)->run($item);

        $this->assertSame('resolved', $adj->verdict);
        $this->assertSame('9018390000', $adj->winning_code);
        $this->assertTrue($adj->stable);
        $this->assertDatabaseCount('classification_adjudications', 1);
    }

    public function test_samples_disagreeing_is_not_stable_and_defers(): void
    {
        $item = $this->divergentItem();
        // Two samples pick DIFFERENT codes → unstable → must not auto-resolve.
        $this->mockJudge([$this->verdict('resolved', '9018390000'), $this->verdict('resolved', '6215200000')]);

        $adj = app(AdjudicatorService::class)->run($item);

        $this->assertFalse($adj->stable);
    }

    public function test_off_list_code_is_forced_uncertain(): void
    {
        config()->set('classify.adjudicator.samples', 1);
        $item = $this->divergentItem();
        $this->mockJudge([$this->verdict('resolved', '1111111111')]); // not among candidates

        $adj = app(AdjudicatorService::class)->run($item);

        $this->assertSame('uncertain', $adj->verdict);
        $this->assertNull($adj->winning_code);
    }

    public function test_run_is_idempotent_per_version(): void
    {
        config()->set('classify.adjudicator.samples', 1);
        $item = $this->divergentItem();
        // Only ONE response provided: a second judge call would fail — proving the
        // cached row is reused on the second run().
        $this->mockJudge([$this->verdict('resolved', '9018390000')]);

        $first = app(AdjudicatorService::class)->run($item);
        $second = app(AdjudicatorService::class)->run($item);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('classification_adjudications', 1);
    }

    public function test_active_mode_applies_resolved_verdict(): void
    {
        config()->set('classify.adjudicator.enabled', true);
        config()->set('classify.adjudicator.mode', 'active');
        config()->set('classify.adjudicator.holdout_pct', 0);
        $item = $this->divergentItem();
        $this->mockJudge([$this->verdict('resolved', '9018390000'), $this->verdict('resolved', '9018390000')]);

        (new AdjudicateItemJob($item->id))->handle(app(AdjudicatorService::class));

        $item->refresh();
        $this->assertSame('ai_resolved', $item->resolution);
        $this->assertSame('9018390000', $item->final_code);
        $this->assertTrue($item->adjudications()->first()->applied);
    }

    public function test_shadow_mode_records_but_does_not_change_resolution(): void
    {
        config()->set('classify.adjudicator.enabled', true);
        config()->set('classify.adjudicator.mode', 'shadow');
        $item = $this->divergentItem();
        $this->mockJudge([$this->verdict('resolved', '9018390000'), $this->verdict('resolved', '9018390000')]);

        (new AdjudicateItemJob($item->id))->handle(app(AdjudicatorService::class));

        $item->refresh();
        $this->assertSame('conflict', $item->resolution);      // untouched
        $this->assertFalse($item->adjudications()->first()->applied);
    }

    public function test_holdout_keeps_item_with_human(): void
    {
        config()->set('classify.adjudicator.enabled', true);
        config()->set('classify.adjudicator.mode', 'active');
        config()->set('classify.adjudicator.holdout_pct', 100); // force holdout
        $item = $this->divergentItem();
        $this->mockJudge([$this->verdict('resolved', '9018390000'), $this->verdict('resolved', '9018390000')]);

        (new AdjudicateItemJob($item->id))->handle(app(AdjudicatorService::class));

        $item->refresh();
        $this->assertSame('conflict', $item->resolution);
        $this->assertTrue($item->adjudications()->first()->holdout);
    }

    public function test_uncertain_verdict_stays_with_human(): void
    {
        config()->set('classify.adjudicator.enabled', true);
        config()->set('classify.adjudicator.mode', 'active');
        config()->set('classify.adjudicator.holdout_pct', 0);
        config()->set('classify.adjudicator.samples', 1);
        $item = $this->divergentItem();
        $this->mockJudge([$this->verdict('uncertain', null)]);

        (new AdjudicateItemJob($item->id))->handle(app(AdjudicatorService::class));

        $this->assertSame('conflict', $item->refresh()->resolution);
    }

    public function test_consensus_dispatches_adjudicator_on_conflict_when_enabled(): void
    {
        Bus::fake();
        config()->set('classify.adjudicator.enabled', true);
        $item = $this->divergentItem('pending'); // resolve() will compute conflict

        app(Consensus::class)->finalize($item);

        $this->assertSame('conflict', $item->refresh()->resolution);
        $this->assertNotNull($item->adjudicated_at);
        Bus::assertDispatched(AdjudicateItemJob::class);
    }

    public function test_consensus_does_not_dispatch_when_disabled(): void
    {
        Bus::fake();
        config()->set('classify.adjudicator.enabled', false);
        $item = $this->divergentItem('pending');

        app(Consensus::class)->finalize($item);

        Bus::assertNotDispatched(AdjudicateItemJob::class);
        $this->assertNull($item->refresh()->adjudicated_at);
    }

    public function test_underdetermined_conflict_skips_the_adjudicator_for_a_human(): void
    {
        Bus::fake();
        config()->set('classify.adjudicator.enabled', true);
        config()->set('classify.mechanisms.enabled', ['vector', 'broker', 'direct']);

        // A mechanism abstained AND the coded ones span different chapters (85 vs 94)
        // → genuinely underdetermined → straight to a human, no adjudicator.
        $item = ClassificationItem::create(['batch' => 't', 'source_text' => 'Elektirov 4lük', 'source_hash' => 'u1', 'resolution' => 'pending']);
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '8539299200', 'kind' => 'good', 'status' => 'auto_confirmed']);
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => '9405401000', 'kind' => 'good', 'status' => 'auto_confirmed']);
        $item->results()->create(['mechanism' => 'direct', 'matched_code' => null, 'status' => 'no_match']);

        app(Consensus::class)->finalize($item);

        $this->assertSame('conflict', $item->refresh()->resolution);
        $this->assertNull($item->refresh()->adjudicated_at);
        Bus::assertNotDispatched(AdjudicateItemJob::class);
    }
}
