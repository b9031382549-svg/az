<?php

namespace Tests\Feature\Classify;

use App\Jobs\ClassifyMechanismJob;
use App\Jobs\SortItemsJob;
use App\Models\ClassificationItem;
use App\Services\Classify\ClassificationQueue;
use App\Services\Classify\Consensus;
use App\Services\Classify\DecisionSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// The sorter step: after memory and the TrashFilter rules, before the AI (SortItemsJob).
// Confident trash is settled with no AI, every verdict is stored, and Direct's "service"
// answer is agreed when the sorter says service too. Fail-open when the service is down.
class SorterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('classify.sorter.url', 'http://sorter.test');
        config()->set('classify.sorter.trash_threshold', 0.99);
        config()->set('classify.mechanisms.enabled', ['vector', 'direct']);
        config()->set('classify.mechanisms.shadow', []);
        config()->set('classify.translate_items', false);
        config()->set('classify.search_resolver.enabled', false);
    }

    /** @param  array<string, array{0: float, 1: float, 2: float}>  $byText  good / service / trash per name */
    private function fakeSorter(array $byText = []): void
    {
        Http::fake(['sorter.test/*' => fn ($request) => Http::response([
            'labels' => ['good', 'service', 'trash'],
            'probs' => array_map(fn ($t) => $byText[$t] ?? [0.98, 0.01, 0.01], $request['texts']),
        ])]);
    }

    private function sortStep(ClassificationItem ...$items): void
    {
        app()->call([new SortItemsJob(array_map(fn ($i) => $i->id, $items)), 'handle']);
    }

    private function item(string $text): ClassificationItem
    {
        return app(ClassificationQueue::class)->createItems([$text], 'b')->first();
    }

    public function test_names_reach_the_ai_only_through_the_sorter_job(): void
    {
        Queue::fake();
        $this->fakeSorter();

        app(ClassificationQueue::class)->enqueue(['Noutbuk', 'Divan'], 'b');

        Queue::assertPushed(SortItemsJob::class, fn ($job) => count($job->itemIds) === 2);
        Queue::assertNotPushed(ClassifyMechanismJob::class);
        Http::assertNothingSent();   // the request path never waits for the model
    }

    public function test_memory_and_the_rules_come_before_the_sorter(): void
    {
        Queue::fake();

        app(ClassificationQueue::class)->enqueue(['Müqaviləyə əsasən', 'Noutbuk'], 'b');

        $noutbuk = ClassificationItem::where('source_text', 'Noutbuk')->value('id');
        Queue::assertPushed(SortItemsJob::class, fn ($job) => $job->itemIds === [$noutbuk]);
    }

    public function test_confident_trash_is_settled_with_no_ai(): void
    {
        Queue::fake();
        $this->fakeSorter(['GrandMart 9 AVM Binə' => [0.001, 0.004, 0.995]]);
        $item = $this->item('GrandMart 9 AVM Binə');

        $this->sortStep($item);

        $item->refresh();
        $this->assertSame('trash', $item->resolution);
        $this->assertNotNull($item->answered_at);
        $trash = $item->results()->where('mechanism', 'trash')->first();
        $this->assertSame('sorter', $trash->trace['rule']);
        $this->assertEqualsWithDelta(0.995, $trash->trace['p'], 1e-9);
        $this->assertSame('trash', $item->results()->where('mechanism', 'sorter')->first()->kind);
        Queue::assertNotPushed(ClassifyMechanismJob::class);
    }

    public function test_a_verdict_below_the_threshold_still_goes_to_the_ai(): void
    {
        Queue::fake();
        $this->fakeSorter(['Findiq' => [0.41, 0.06, 0.53]]);
        $item = $this->item('Findiq');

        $this->sortStep($item);

        $this->assertSame('pending', $item->fresh()->resolution);
        $sorter = $item->results()->where('mechanism', 'sorter')->first();
        $this->assertSame('trash', $sorter->kind);
        $this->assertEqualsWithDelta(0.53, $sorter->confidence, 1e-9);
        $this->assertEqualsWithDelta(0.41, $sorter->trace['probs']['good'], 1e-9);
        Queue::assertPushed(ClassifyMechanismJob::class, 2);
    }

    public function test_when_the_service_is_down_the_items_go_to_the_ai(): void
    {
        Queue::fake();
        Http::fake(['sorter.test/*' => Http::response('boom', 500)]);
        $item = $this->item('Noutbuk');

        $this->sortStep($item);

        $this->assertFalse($item->results()->where('mechanism', 'sorter')->exists());
        Queue::assertPushed(ClassifyMechanismJob::class, 2);
    }

    public function test_a_job_that_fails_for_good_still_sends_its_items_on(): void
    {
        Queue::fake();
        $item = $this->item('Noutbuk');

        (new SortItemsJob([$item->id]))->failed(new \RuntimeException('x'));

        Queue::assertPushed(ClassifyMechanismJob::class, 2);
    }

    public function test_without_the_sorter_names_go_straight_to_the_ai(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('classify.sorter.url', '');

        app(ClassificationQueue::class)->enqueue(['Noutbuk'], 'b');

        Queue::assertNotPushed(SortItemsJob::class);
        Queue::assertPushed(ClassifyMechanismJob::class, 2);
        Http::assertNothingSent();
    }

    public function test_a_reviewer_can_send_sorter_trash_to_the_ai_and_it_is_not_trashed_again(): void
    {
        Queue::fake();
        $this->fakeSorter(['Market' => [0.001, 0.004, 0.995]]);
        $item = $this->item('Market');
        $this->sortStep($item);

        $this->assertTrue(app(ClassificationQueue::class)->classifyAnyway($item->fresh()));
        $this->sortStep($item);   // a second pass must not undo the reviewer

        $item->refresh();
        $this->assertSame('pending', $item->resolution);
        $this->assertSame('overridden', $item->results()->where('mechanism', 'trash')->first()->status);
        Queue::assertPushed(ClassifyMechanismJob::class);
    }

    /** Direct + vector rows for an item, and optionally the sorter's verdict. */
    private function decided(string $text, string $directCode, string $directKind, array $vectorCodes, ?string $sorterKind): ClassificationItem
    {
        $item = $this->item($text);
        $item->results()->create(['mechanism' => 'direct', 'matched_code' => $directCode, 'kind' => $directKind, 'status' => 'auto_confirmed']);
        $item->results()->create([
            'mechanism' => 'vector', 'matched_code' => $vectorCodes[0], 'kind' => 'good', 'status' => 'auto_confirmed',
            'candidates' => array_map(fn ($c) => ['code' => $c, 'kind' => str_starts_with($c, '99') ? 'service' : 'good'], $vectorCodes),
        ]);
        if ($sorterKind !== null) {
            $item->results()->create(['mechanism' => 'sorter', 'kind' => $sorterKind, 'confidence' => 0.97, 'status' => 'sorted', 'trace' => ['probs' => []]]);
        }

        return $item;
    }

    public function test_direct_service_is_agreed_when_the_sorter_says_service(): void
    {
        $item = $this->decided('Marketinq xidməti', '99', 'service', ['9403000000', '4911000000', '1604000000'], 'service');

        app(Consensus::class)->finalize($item);

        $item->refresh();
        $this->assertSame('agreed', $item->resolution);
        $this->assertSame('99', $item->final_code);
        $this->assertSame('service', $item->kind);
        $summary = app(DecisionSummary::class)->forItems(collect([$item]))[$item->id];
        $this->assertSame('sorter', $summary['method']);
        $this->assertStringContainsString('97%', $summary['reason']);
    }

    public function test_direct_service_without_the_sorters_service_is_a_conflict(): void
    {
        $item = $this->decided('Marketinq xidməti', '99', 'service', ['9403000000', '4911000000', '1604000000'], 'good');

        app(Consensus::class)->finalize($item);

        $this->assertSame('conflict', $item->fresh()->resolution);
    }

    public function test_a_goods_answer_ignores_the_sorter(): void
    {
        $item = $this->decided('Noutbuk', '8471', 'good', ['9403000000', '4911000000', '1604000000'], 'service');

        app(Consensus::class)->finalize($item);

        $this->assertSame('conflict', $item->fresh()->resolution);
    }

    public function test_a_missing_verdict_is_asked_for_when_direct_says_service(): void
    {
        $this->fakeSorter(['Marketinq xidməti' => [0.01, 0.98, 0.01]]);
        $item = $this->decided('Marketinq xidməti', '99', 'service', ['9403000000', '4911000000', '1604000000'], null);

        app(Consensus::class)->finalize($item);

        $this->assertSame('agreed', $item->fresh()->resolution);
        $this->assertSame('service', $item->results()->where('mechanism', 'sorter')->first()->kind);
        Http::assertSentCount(1);
    }

    public function test_the_trash_reason_names_the_sorter_and_its_probability(): void
    {
        Queue::fake();
        $this->fakeSorter(['Paytaxt' => [0.0005, 0.0015, 0.998]]);
        $item = $this->item('Paytaxt');
        $this->sortStep($item);

        $summary = app(DecisionSummary::class)->forItems(collect([$item->fresh()]))[$item->id];

        $this->assertSame('trash', $summary['method']);
        $this->assertStringContainsString('sorter', $summary['reason']);
        $this->assertStringContainsString('99.8%', $summary['reason']);
    }
}
