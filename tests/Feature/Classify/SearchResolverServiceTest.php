<?php

namespace Tests\Feature\Classify;

use App\Models\AnswerCache;
use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Models\TestDataset;
use App\Models\TestRun;
use App\Services\Classify\ProductBriefService;
use App\Services\Classify\SearchResolverService;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SearchResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A real 4-digit heading the resolver can validate against (position is indexed).
        CatalogCode::create(['code' => '8471300000', 'name' => 'noutbuk', 'name_en' => 'laptops', 'kind' => 'good', 'chapter' => '84', 'position' => '8471', 'subposition' => '847130', 'is_active' => true]);
        config()->set('classify.search_resolver.min_confidence', 0.8);
    }

    private function mockLlm(string $content, array $annotations = []): void
    {
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('complete')->andReturn([
            'content' => $content,
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            'model' => 'deepseek/deepseek-v4-flash:online',
            'annotations' => $annotations,
        ]);
        $this->instance(OpenRouterClient::class, $llm);
    }

    private function conflictItem(): ClassificationItem
    {
        return ClassificationItem::create(['batch' => 't', 'source_text' => 'noutbuk kompüter', 'source_hash' => 'h'.mt_rand(), 'resolution' => 'conflict']);
    }

    public function test_confident_real_heading_resolves_at_4_digits(): void
    {
        $item = $this->conflictItem();
        $this->mockLlm('reasoning...{"heading":"8471","kind":"good","confidence":0.95,"reason":"a laptop"}');

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('ai_resolved', $item->resolution);
        $this->assertSame('8471', $item->final_code);        // 4-digit heading only
        $this->assertNull($item->final_catalog_id);
        $this->assertSame('good', $item->kind);

        $trace = $item->results()->where('mechanism', 'search')->first();
        $this->assertSame('8471', $trace->matched_code);
        $this->assertSame('auto_confirmed', $trace->status);
        $this->assertSame(0.95, $trace->confidence);
    }

    public function test_service_resolves_at_99(): void
    {
        $item = $this->conflictItem();
        $this->mockLlm('{"heading":"99","kind":"service","confidence":0.9,"reason":"a repair"}');

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('ai_resolved', $item->resolution);
        $this->assertSame('99', $item->final_code);
        $this->assertSame('service', $item->kind);
    }

    public function test_low_confidence_stays_conflict_for_a_human(): void
    {
        $item = $this->conflictItem();
        $this->mockLlm('{"heading":"8471","confidence":0.4,"reason":"unsure"}');

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('conflict', $item->resolution);    // handed to a human
        $this->assertNull($item->final_code);
        // …but the search attempt is recorded so the reviewer sees it.
        $this->assertSame('needs_review', $item->results()->where('mechanism', 'search')->first()->status);
    }

    public function test_unknown_heading_stays_conflict(): void
    {
        $item = $this->conflictItem();
        // 1234 is confident but not a real heading in the catalog → cannot be trusted.
        $this->mockLlm('{"heading":"1234","confidence":0.99,"reason":"guess"}');

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('conflict', $item->resolution);
        $this->assertNull($item->results()->where('mechanism', 'search')->first()->matched_code);
    }

    public function test_llm_failure_stays_conflict_and_records_the_attempt(): void
    {
        $item = $this->conflictItem();
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('complete')->andThrow(new RuntimeException('timed out'));
        $this->instance(OpenRouterClient::class, $llm);

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('conflict', $item->resolution);
        $this->assertSame('no_match', $item->results()->where('mechanism', 'search')->first()->status);
    }

    public function test_never_clobbers_a_human_decision(): void
    {
        $item = $this->conflictItem();
        $item->update(['resolution' => 'confirmed', 'final_code' => '6215200000']); // human already decided
        $this->mockLlm('{"heading":"8471","confidence":0.99,"reason":"laptop"}');

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('confirmed', $item->resolution);   // untouched
        $this->assertSame('6215200000', $item->final_code);
    }

    public function test_out_of_scale_confidence_is_not_trusted(): void
    {
        $item = $this->conflictItem();
        // A model returning 55 (or "high") must NOT bypass the 0.8 gate and auto-resolve.
        $this->mockLlm('{"heading":"8471","confidence":55,"reason":"broken scale"}');

        app(SearchResolverService::class)->resolve($item);

        $this->assertSame('conflict', $item->refresh()->resolution);
    }

    public function test_web_citations_are_folded_into_the_reason(): void
    {
        $item = $this->conflictItem();
        $this->mockLlm(
            '{"heading":"8471","confidence":0.95,"reason":"a laptop"}',
            [['url' => 'https://ru.wikipedia.org/wiki/x', 'title' => 't']],
        );

        app(SearchResolverService::class)->resolve($item);

        $this->assertStringContainsString('[web: ru.wikipedia.org]', (string) $item->results()->where('mechanism', 'search')->first()->explanation);
    }

    // --- Flow v2: self-consistency ensemble resolver -------------------------------------

    private function mockBrief(): void
    {
        $briefs = Mockery::mock(ProductBriefService::class);
        $briefs->shouldReceive('brief')->andReturn([
            'identity' => 'laptop computer',
            'az_reading' => 'portable notebook PC',
            'synonyms' => ['notebook', 'portable computer'],
        ]);
        $this->instance(ProductBriefService::class, $briefs);
    }

    /** A vector trace whose top-K headings are the ensemble's shortlist. */
    private function seedVectorShortlist(ClassificationItem $item, array $codes): void
    {
        $item->results()->create([
            'mechanism' => 'vector', 'matched_code' => $codes[0], 'kind' => 'good',
            'status' => 'needs_review', 'candidates' => array_map(fn ($c) => ['code' => $c], $codes),
        ]);
    }

    public function test_ensemble_agreement_resolves_locally_and_skips_the_web(): void
    {
        config()->set('classify.flow.ensemble_resolver', true);
        config()->set('classify.flow.shadow', false);
        $this->mockBrief();

        $item = $this->conflictItem();
        $this->seedVectorShortlist($item, ['8471300000']);
        // Every grounded-chooser call agrees on 8471 → unanimous → commit, no web.
        $this->mockLlm('{"heading":"8471"}');

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('ai_resolved', $item->resolution);
        $this->assertSame('8471', $item->final_code);
        $ens = $item->results()->where('mechanism', 'ensemble')->first();
        $this->assertSame('unanimous', $ens->trace['agreement']);
        $this->assertSame('auto_confirmed', $ens->status);
        // The paid web resolver was skipped entirely — no 'search' trace row.
        $this->assertNull($item->results()->where('mechanism', 'search')->first());
    }

    public function test_ensemble_split_vote_abstains_and_falls_through_to_web(): void
    {
        config()->set('classify.flow.ensemble_resolver', true);
        config()->set('classify.flow.shadow', false);
        $this->mockBrief();

        $item = $this->conflictItem();
        $this->seedVectorShortlist($item, ['8471300000', '8528720000', '8517120000']);
        // 3 groundings → 3 different valid headings → split; then the web call resolves.
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('complete')->andReturn(
            ['content' => '{"heading":"8471"}', 'usage' => [], 'model' => 'm', 'annotations' => []],
            ['content' => '{"heading":"8528"}', 'usage' => [], 'model' => 'm', 'annotations' => []],
            ['content' => '{"heading":"8517"}', 'usage' => [], 'model' => 'm', 'annotations' => []],
            ['content' => '{"heading":"8471","confidence":0.95,"reason":"laptop"}', 'usage' => [], 'model' => 'deepseek/deepseek-v4-flash:online', 'annotations' => []],
        );
        $this->instance(OpenRouterClient::class, $llm);

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('split', $item->results()->where('mechanism', 'ensemble')->first()->trace['agreement']);
        // Fell through to the web resolver, which settled it.
        $this->assertSame('ai_resolved', $item->resolution);
        $this->assertSame('8471', $item->final_code);
        $this->assertNotNull($item->results()->where('mechanism', 'search')->first());
    }

    public function test_shadow_records_the_ensemble_but_serves_the_web_answer(): void
    {
        config()->set('classify.flow.ensemble_resolver', true);
        config()->set('classify.flow.shadow', true); // shadow: compute + log, serve old
        $this->mockBrief();

        $item = $this->conflictItem();
        $this->seedVectorShortlist($item, ['8471300000']);
        // Ensemble unanimously agrees AND the web is confident — but shadow means the WEB
        // answer is the one served, and the ensemble is only recorded.
        $this->mockLlm('{"heading":"8471","confidence":0.95,"reason":"laptop"}');

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('ai_resolved', $item->resolution);
        $ens = $item->results()->where('mechanism', 'ensemble')->first();
        $this->assertSame('unanimous', $ens->trace['agreement']);
        $this->assertSame('shadow', $ens->status);           // recorded, not applied
        $this->assertTrue($ens->trace['shadow']);
        // The web resolver still ran and produced the served answer.
        $this->assertNotNull($item->results()->where('mechanism', 'search')->first());
    }

    // --- Grounded memory write-back (memory_promotion.grounded_search) -------------------

    private function enableGroundedPromotion(): void
    {
        config()->set('classify.memory_promotion.grounded_search.enabled', true);
        config()->set('classify.mechanisms.enabled', ['vector', 'broker', 'direct']);
        config()->set('classify.mechanisms.shadow', []);
    }

    public function test_grounded_and_confident_answer_is_written_to_memory(): void
    {
        $this->enableGroundedPromotion();
        $item = $this->conflictItem();
        // 'direct' already proposed 8471 before the conflict — search's answer overlaps it.
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '620800', 'kind' => 'good', 'status' => 'needs_review']);
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => '940320', 'kind' => 'good', 'status' => 'needs_review']);
        $item->results()->create(['mechanism' => 'direct', 'matched_code' => '8471', 'kind' => 'good', 'status' => 'needs_review']);
        $this->mockLlm('{"heading":"8471","kind":"good","confidence":0.98,"reason":"a laptop"}');

        app(SearchResolverService::class)->resolve($item);

        $row = AnswerCache::where('name_key', AnswerCache::keyFor($item->source_text))->first();
        $this->assertNotNull($row);
        $this->assertSame('8471', $row->heading);
        $this->assertSame('ai_resolved_grounded', $row->source);
        $this->assertSame(0, (int) $row->test_dataset_id); // a prod item → production memory (scope 0)
    }

    public function test_grounded_write_is_off_by_default(): void
    {
        // Explicit disable — don't rely on ambient .env (a local dev box may have this
        // flag turned on, as this exact feature does once someone tests it live).
        config()->set('classify.memory_promotion.grounded_search.enabled', false);
        $item = $this->conflictItem();
        $item->results()->create(['mechanism' => 'direct', 'matched_code' => '8471', 'kind' => 'good', 'status' => 'needs_review']);
        $this->mockLlm('{"heading":"8471","kind":"good","confidence":0.99,"reason":"a laptop"}');

        app(SearchResolverService::class)->resolve($item);

        $this->assertDatabaseCount('answer_cache', 0);
    }

    public function test_ungrounded_answer_is_not_written_even_at_full_confidence(): void
    {
        $this->enableGroundedPromotion();
        $item = $this->conflictItem();
        // None of the three proposed 8471 — search's answer isn't corroborated by anything.
        $item->results()->create(['mechanism' => 'vector', 'matched_code' => '620800', 'kind' => 'good', 'status' => 'needs_review']);
        $item->results()->create(['mechanism' => 'broker', 'matched_code' => '940320', 'kind' => 'good', 'status' => 'needs_review']);
        $item->results()->create(['mechanism' => 'direct', 'matched_code' => null, 'kind' => null, 'status' => 'no_match']);
        $this->mockLlm('{"heading":"8471","kind":"good","confidence":1.0,"reason":"a laptop"}');

        app(SearchResolverService::class)->resolve($item);

        $this->assertDatabaseCount('answer_cache', 0);
    }

    public function test_grounded_but_below_090_confidence_is_not_written(): void
    {
        $this->enableGroundedPromotion();
        $item = $this->conflictItem();
        $item->results()->create(['mechanism' => 'direct', 'matched_code' => '8471', 'kind' => 'good', 'status' => 'needs_review']);
        $this->mockLlm('{"heading":"8471","kind":"good","confidence":0.85,"reason":"a laptop"}');

        app(SearchResolverService::class)->resolve($item);

        $this->assertDatabaseCount('answer_cache', 0);
    }

    public function test_grounded_write_never_overwrites_an_existing_answer(): void
    {
        $this->enableGroundedPromotion();
        AnswerCache::create(['test_dataset_id' => 0, 'source' => 'fedor', 'name' => 'noutbuk kompüter',
            'name_key' => AnswerCache::keyFor('noutbuk kompüter'), 'heading' => '8528', 'is_service' => false]);
        $item = $this->conflictItem(); // same source_text: 'noutbuk kompüter'
        $item->results()->create(['mechanism' => 'direct', 'matched_code' => '8471', 'kind' => 'good', 'status' => 'needs_review']);
        $this->mockLlm('{"heading":"8471","kind":"good","confidence":0.99,"reason":"a laptop"}');

        app(SearchResolverService::class)->resolve($item);

        $row = AnswerCache::where('name_key', AnswerCache::keyFor('noutbuk kompüter'))->first();
        $this->assertSame('fedor', $row->source);
        $this->assertSame('8528', $row->heading); // untouched
        $this->assertDatabaseCount('answer_cache', 1);
    }

    public function test_grounded_answer_from_a_test_run_warms_the_dataset_not_prod(): void
    {
        // The SAME prod resolver runs for a Testing-row item (via ClassifyTestSearchJob), so
        // its grounded write must land in the run's OWN dataset memory — never scope 0, which
        // the live classifier reads. Without this a test run silently pollutes production.
        $this->enableGroundedPromotion();
        $dataset = TestDataset::create(['name' => 'ds', 'mechanisms' => ['enabled' => ['vector', 'broker', 'direct'], 'shadow' => [], 'cache' => false, 'search' => true]]);
        $run = TestRun::create(['test_dataset_id' => $dataset->id, 'description' => 'r', 'mechanisms' => [], 'config' => [], 'status' => 'running']);
        $item = ClassificationItem::create(['batch' => TestRun::batchKey($run->id), 'source_text' => 'noutbuk kompüter', 'source_hash' => 'h'.mt_rand(), 'resolution' => 'conflict', 'test_run_id' => $run->id]);
        $item->results()->create(['mechanism' => 'direct', 'matched_code' => '8471', 'kind' => 'good', 'status' => 'needs_review']);
        $this->mockLlm('{"heading":"8471","kind":"good","confidence":0.98,"reason":"a laptop"}');

        app(SearchResolverService::class)->resolve($item);

        $this->assertSame('ai_resolved', $item->refresh()->resolution);
        $row = AnswerCache::where('name_key', AnswerCache::keyFor('noutbuk kompüter'))->first();
        $this->assertNotNull($row);
        $this->assertSame($dataset->id, (int) $row->test_dataset_id); // its DATASET memory, not prod (0)
        $this->assertSame('8471', $row->heading);
        $this->assertSame('ai_resolved_grounded', $row->source);
    }
}
