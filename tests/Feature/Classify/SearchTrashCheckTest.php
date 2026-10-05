<?php

namespace Tests\Feature\Classify;

use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Models\TestDataset;
use App\Models\TestRun;
use App\Services\Api\ModelVersion;
use App\Services\Classify\CatalogRetriever;
use App\Services\Classify\DecisionSummary;
use App\Services\Classify\SearchResolverService;
use App\Services\Classify\TrashFilter;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** The web search's "names no product" check (classify.search_resolver.trash_check). */
class SearchTrashCheckTest extends TestCase
{
    use RefreshDatabase;

    private const NO_PRODUCT = '{"names_product":false,"none_reason":"person","identity":"","az_reading":"","synonyms":[]}';

    private const LAPTOP = '{"names_product":true,"none_reason":null,"identity":"laptop computer","az_reading":"portable notebook PC","synonyms":["notebook"]}';

    /** @var array<int, array<int, array{role: string, content: string}>> the messages of every LLM call */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        CatalogCode::create(['code' => '8471300000', 'name' => 'noutbuk', 'name_en' => 'laptops', 'kind' => 'good', 'chapter' => '84', 'position' => '8471', 'subposition' => '847130', 'is_active' => true]);
        config()->set('classify.flow.ensemble_resolver', true);
        config()->set('classify.flow.shadow', false);
        config()->set('classify.search_resolver.trash_check.enabled', true);
        config()->set('classify.search_resolver.trash_check.min_sorter_trash', 0.2);

        $ret = Mockery::mock(CatalogRetriever::class);
        $ret->shouldReceive('candidates')->andReturn([(object) ['code' => '8471300000']]);
        $this->instance(CatalogRetriever::class, $ret);
    }

    /** Every LLM call answers with the next of $contents (the last one repeats). */
    private function mockLlm(array $contents): void
    {
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('complete')->andReturnUsing(function (array $messages) use (&$contents) {
            $this->calls[] = $messages;
            $content = count($contents) > 1 ? array_shift($contents) : $contents[0];

            return ['content' => $content, 'usage' => [], 'model' => 'deepseek/deepseek-v4-flash:online', 'annotations' => []];
        });
        $this->instance(OpenRouterClient::class, $llm);
    }

    private function conflictItem(string $text = 'Abbaslı Nigar Qurban qızı', ?float $pTrash = 0.9, array $attrs = []): ClassificationItem
    {
        $item = ClassificationItem::create(['batch' => 't', 'source_text' => $text, 'source_hash' => 'h'.mt_rand(), 'resolution' => 'conflict'] + $attrs);
        if ($pTrash !== null) {
            $item->results()->create([
                'mechanism' => 'sorter', 'kind' => $pTrash >= 0.5 ? 'trash' : 'good', 'status' => 'sorted', 'confidence' => $pTrash,
                'candidates' => [], 'model' => 'sorter',
                'trace' => ['probs' => ['good' => 1 - $pTrash, 'service' => 0.0, 'trash' => $pTrash], 'trash_threshold' => 0.99747],
            ]);
        }

        return $item;
    }

    public function test_a_line_naming_no_product_becomes_trash_when_the_sorter_agrees(): void
    {
        $item = $this->conflictItem();
        $this->mockLlm([self::NO_PRODUCT]);

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('trash', $item->resolution);
        $this->assertNull($item->final_code);
        $this->assertNotNull($item->answered_at);
        $row = $item->results()->where('mechanism', 'trash')->first();
        $this->assertSame('search', $row->trace['rule']);
        $this->assertSame('person', $row->trace['reason']);
        $this->assertSame(0.9, $row->trace['p']);
        // One call (the understanding) and nothing after it: no chooser votes, no web search.
        $this->assertCount(1, $this->calls);
        $this->assertNull($item->results()->where('mechanism', 'ensemble')->first());
        $this->assertNull($item->results()->where('mechanism', 'search')->first());
        $this->assertStringContainsString('names_product', $this->calls[0][0]['content']);
    }

    public function test_without_the_sorters_backing_the_line_goes_on_as_before(): void
    {
        $item = $this->conflictItem(pTrash: 0.1);
        // The understanding names no product; the web search then cannot settle it either.
        $this->mockLlm([self::NO_PRODUCT, '{"heading":null,"confidence":0.1,"reason":"a person"}']);

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('conflict', $item->resolution);   // a human decides, as before
        $this->assertNull($item->results()->where('mechanism', 'trash')->first());
        $this->assertNotNull($item->results()->where('mechanism', 'search')->first());
    }

    public function test_no_sorter_verdict_means_no_trash(): void
    {
        $item = $this->conflictItem(pTrash: null);
        $this->mockLlm([self::NO_PRODUCT, '{"heading":null,"confidence":0.1,"reason":"?"}']);

        app(SearchResolverService::class)->resolve($item);

        $this->assertSame('conflict', $item->fresh()->resolution);
    }

    public function test_a_product_goes_on_to_the_vote_as_before(): void
    {
        $item = $this->conflictItem('noutbuk kompüter', 0.9);
        $this->mockLlm([self::LAPTOP, '{"heading":"8471"}']);

        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $this->assertSame('ai_resolved', $item->resolution);
        $this->assertSame('8471', $item->final_code);
        $this->assertSame('laptop computer', $item->results()->where('mechanism', 'ensemble')->first()->trace['understanding']['identity']);
    }

    public function test_switched_off_the_old_prompt_is_sent_and_never_trashes(): void
    {
        config()->set('classify.search_resolver.trash_check.enabled', false);
        $item = $this->conflictItem();
        $this->mockLlm([self::NO_PRODUCT, '{"heading":null,"confidence":0.1,"reason":"?"}']);

        app(SearchResolverService::class)->resolve($item);

        $this->assertSame('conflict', $item->fresh()->resolution);
        $this->assertStringNotContainsString('names_product', $this->calls[0][0]['content']);
        $this->assertStringContainsString('NEVER a video game', $this->calls[0][0]['content']);
    }

    public function test_a_line_a_reviewer_took_out_of_trash_is_never_trashed_again(): void
    {
        $item = $this->conflictItem();
        $item->results()->create(['mechanism' => 'trash', 'status' => 'overridden', 'candidates' => [], 'trace' => ['rule' => 'search']]);
        $this->mockLlm([self::NO_PRODUCT, '{"heading":null,"confidence":0.1,"reason":"?"}']);

        app(SearchResolverService::class)->resolve($item);

        $this->assertSame('conflict', $item->fresh()->resolution);
        $this->assertSame('overridden', $item->results()->where('mechanism', 'trash')->first()->status);
    }

    public function test_a_testing_run_switches_the_check_for_itself(): void
    {
        config()->set('classify.search_resolver.trash_check.enabled', false);   // prod: off
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => []]);
        $run = TestRun::create(['test_dataset_id' => $dataset->id, 'description' => 'r', 'config' => [], 'status' => 'running', 'total' => 1,
            'mechanisms' => ['enabled' => ['vector', 'direct'], 'shadow' => [], 'cache' => false, 'search' => true, 'trash_check' => true]]);
        $item = $this->conflictItem(attrs: ['test_run_id' => $run->id]);
        $this->mockLlm([self::NO_PRODUCT]);

        app(SearchResolverService::class)->resolve($item);

        $this->assertSame('trash', $item->fresh()->resolution);
    }

    public function test_the_reason_is_normalised_and_explained(): void
    {
        $this->assertSame('person', TrashFilter::noneReason(' Person '));
        $this->assertSame('address', TrashFilter::noneReason('place'));
        $this->assertNull(TrashFilter::noneReason('brand'));

        $item = $this->conflictItem();
        $this->mockLlm([self::NO_PRODUCT]);
        app(SearchResolverService::class)->resolve($item);

        $item->refresh();
        $summary = app(DecisionSummary::class)->describe($item, $item->results);
        $this->assertSame('trash', $summary['method']);
        $this->assertStringContainsString("a person's name", $summary['reason']);
    }

    public function test_api_version_names_the_check_when_it_is_on(): void
    {
        config()->set('classify.search_resolver.enabled', true);
        $this->assertArrayHasKey('web_search_trash_check', app(ModelVersion::class)->toArray()['components']);

        config()->set('classify.search_resolver.trash_check.enabled', false);
        $this->assertArrayNotHasKey('web_search_trash_check', app(ModelVersion::class)->toArray()['components']);
    }
}
