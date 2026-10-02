<?php

namespace Tests\Feature\Testing;

use App\Jobs\ClassifyTestItemMechanismJob;
use App\Jobs\ScoreRunJob;
use App\Jobs\SortTestItemsJob;
use App\Livewire\TestingRun;
use App\Models\ClassificationItem;
use App\Models\TestDataset;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Testing\RunScorer;
use App\Services\Testing\TestRunner;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

// A test run takes the production path: only-digits → memory → the trash rules → the sorter
// (sure trash is taken out before the AI) → the AI mechanisms. Every row goes this way, those
// that carry only a kind (TRASH / GOOD / SERVICE) too. "Overall" counts a coded row the trash
// step took out as a miss (prod leaves it without a code) and scores kind-only rows by the kind
// they ended as; the "Sorter" column still scores the model alone, on every row.
class SorterTestingTest extends TestCase
{
    use RefreshDatabase;

    private const MECH = ['enabled' => ['vector'], 'shadow' => [], 'cache' => false, 'search' => false];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('classify.sorter.url', 'http://sorter.test');
        config()->set('classify.sorter.trash_threshold', 0.99);
    }

    private function dataset(): TestDataset
    {
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => self::MECH]);
        $dataset->rows()->createMany([
            ['source_text' => 'Coffee beans', 'expected_heading' => '0901', 'expected_is_service' => false, 'expected_type' => 'good'],
            ['source_text' => '12345', 'expected_heading' => '8471', 'expected_is_service' => false, 'expected_type' => 'good'],
            ['source_text' => '0011 saylı 11.01.2018-ci il tarixli məktub', 'expected_code' => 'TRASH', 'expected_is_service' => false, 'expected_type' => 'trash'],
            ['source_text' => 'GrandMart 9 AVM Binə', 'expected_code' => 'TRASH', 'expected_is_service' => false, 'expected_type' => 'trash'],
            ['source_text' => 'Pivə 0,5', 'expected_code' => 'GOOD', 'expected_is_service' => false, 'expected_type' => 'good'],
        ]);

        return $dataset;
    }

    /** @return array<int, ClassificationItem> */
    private function items(TestRun $run): array
    {
        return ClassificationItem::where('test_run_id', $run->id)->orderBy('id')->get()->all();
    }

    public function test_the_rules_settle_their_rows_and_the_rest_waits_for_the_sorter(): void
    {
        Bus::fake();

        $run = app(TestRunner::class)->launch($this->dataset(), 'r', self::MECH);

        [$coffee, $digits, $letter, $mart, $beer] = $this->items($run);
        $this->assertSame(['pending', 'trash', 'trash', 'pending', 'pending'], array_map(fn ($i) => $i->fresh()->resolution, $this->items($run)));
        $this->assertSame('no_letters', $digits->results()->where('mechanism', 'trash')->first()->trace['rule']);
        $this->assertSame('paperwork', $letter->results()->where('mechanism', 'trash')->first()->trace['rule']);
        // One sorter job: verdicts for all five, the trash step for the three still in the flow —
        // kind-only rows included. No AI yet: the sorter job enlists it.
        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof SortTestItemsJob
            && $batch->jobs->first()->itemIds === [$coffee->id, $digits->id, $letter->id, $mart->id, $beer->id]
            && $batch->jobs->first()->flow === [$coffee->id, $mart->id, $beer->id]);
    }

    public function test_without_the_sorter_the_flow_goes_straight_to_the_ai(): void
    {
        Bus::fake();
        config()->set('classify.sorter.url', '');

        $run = app(TestRunner::class)->launch($this->dataset(), 'r', self::MECH);

        [$coffee, , , $mart, $beer] = $this->items($run);
        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->every(fn ($j) => $j instanceof ClassifyTestItemMechanismJob)
            && $batch->jobs->pluck('itemId')->all() === [$coffee->id, $mart->id, $beer->id]);
    }

    public function test_the_sort_job_takes_sure_trash_out_and_sends_the_rest_to_the_ai(): void
    {
        Bus::fake();
        Http::fake(['sorter.test/*' => fn ($request) => Http::response([
            'labels' => ['good', 'service', 'trash'],
            'probs' => array_map(fn ($t) => $t === 'GrandMart 9 AVM Binə' ? [0.001, 0.004, 0.995] : [0.98, 0.01, 0.01], $request['texts']),
        ])]);
        $run = app(TestRunner::class)->launch($this->dataset(), 'r', self::MECH);
        [$coffee, $digits, $letter, $mart, $beer] = $this->items($run);

        [$job, $batch] = (new SortTestItemsJob([$coffee->id, $digits->id, $letter->id, $mart->id, $beer->id], [$coffee->id, $mart->id, $beer->id]))->withFakeBatch();
        app()->call([$job, 'handle']);

        $this->assertSame('trash', $mart->fresh()->resolution);   // sure → out before the AI, as prod
        $this->assertSame('sorter', $mart->results()->where('mechanism', 'trash')->first()->trace['rule']);
        $this->assertSame('pending', $coffee->fresh()->resolution);
        $this->assertSame('pending', $beer->fresh()->resolution);
        $this->assertSame('good', $letter->results()->where('mechanism', 'sorter')->value('kind'));   // a verdict for every row
        $this->assertSame('trash', $letter->fresh()->resolution);                                      // the rule's answer stands
        $this->assertSame([$coffee->id, $beer->id], collect($batch->added)->pluck('itemId')->all());
        $this->assertTrue(collect($batch->added)->every(fn ($j) => $j instanceof ClassifyTestItemMechanismJob && $j->mechanism === 'vector'));
    }

    public function test_a_sorter_that_is_down_sends_the_whole_flow_to_the_ai(): void
    {
        Bus::fake();
        Http::fake(['sorter.test/*' => Http::response('boom', 500)]);
        $run = app(TestRunner::class)->launch($this->dataset(), 'r', self::MECH);
        [$coffee, , , $mart, $beer] = $this->items($run);

        [$job, $batch] = (new SortTestItemsJob([$coffee->id, $mart->id, $beer->id], [$coffee->id, $mart->id, $beer->id]))->withFakeBatch();
        app()->call([$job, 'handle']);

        $this->assertSame('pending', $mart->fresh()->resolution);
        $this->assertSame([$coffee->id, $mart->id, $beer->id], collect($batch->added)->pluck('itemId')->all());
    }

    public function test_a_hard_failed_sort_job_still_lets_the_run_finish(): void
    {
        Bus::fake();
        $run = app(TestRunner::class)->launch($this->dataset(), 'r', self::MECH);
        [$coffee, , , $mart] = $this->items($run);

        (new SortTestItemsJob([$coffee->id, $mart->id], [$coffee->id, $mart->id]))->failed(new \RuntimeException('killed'));

        $this->assertSame('no_match', $coffee->fresh()->resolution);
        $this->assertNotNull($coffee->fresh()->answered_at);
        Bus::assertDispatched(ScoreRunJob::class, fn ($j) => $j->runId === $run->id);
    }

    public function test_the_scorer_counts_the_trash_step_in_overall_and_scores_kind_only_rows_by_kind(): void
    {
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => self::MECH]);
        $run = TestRun::create(['test_dataset_id' => $dataset->id, 'description' => 'r', 'batch' => 'b', 'mechanisms' => self::MECH, 'config' => [], 'status' => 'running', 'total' => 7]);
        $rows = [
            // [text, heading, service, type, resolution, final code, trash rule]
            ['Coffee beans', '0901', false, 'good', 'agreed', '0901', null],               // ✓
            ['Market', '0901', false, 'good', 'trash', null, 'sorter'],                    // a good the sorter took out: a miss
            ['0011 saylı məktub', '99', true, 'service', 'trash', null, 'paperwork'],      // a rule took it out: a miss
            ['GrandMart 9 AVM Binə', null, false, 'trash', 'trash', null, 'sorter'],       // ✓ trash
            ['Nəsə', null, false, 'trash', 'agreed', '9403', null],                        // the AI coded a trash line: ✗
            ['Pivə 0,5', null, false, 'good', 'agreed', '2203', null],                     // ✓ ended as a good
            ['Consulting', null, true, 'service', 'agreed', '99', null],                   // ✓ ended as a service
        ];
        foreach ($rows as $i => [$text, $heading, $service, $type, $resolution, $code, $rule]) {
            $row = $dataset->rows()->create(['source_text' => $text, 'expected_heading' => $heading, 'expected_is_service' => $service && $heading !== null, 'expected_type' => $type]);
            $item = ClassificationItem::create(['batch' => 'b', 'test_run_id' => $run->id, 'test_dataset_row_id' => $row->id, 'source_text' => $text, 'source_hash' => "h{$i}",
                'resolution' => $resolution, 'final_code' => $code, 'kind' => $code === null ? null : ($code === '99' ? 'service' : 'good')]);
            if ($rule !== null) {
                $item->results()->create(['mechanism' => 'trash', 'status' => 'trash', 'trace' => ['rule' => $rule]]);
            }
            if ($resolution === 'agreed' && $heading !== null) {
                $item->results()->create(['mechanism' => 'vector', 'matched_code' => $heading.'000000', 'kind' => 'good', 'status' => 'auto_confirmed', 'candidates' => [['code' => $heading.'000000', 'kind' => 'good']]]);
            }
        }

        $score = app(RunScorer::class)->score($run);

        $this->assertSame(['ran' => 7, 'answered' => 5, 'correct' => 4], $score['columns']['overall']);   // a coded row taken out has no code; for a kind-only row "trash" is an answer
        $this->assertSame(['removed' => 3, 'right' => 1, 'by_rules' => 1, 'by_sorter' => 2, 'trash_rows' => 2], $score['trash']);
        $this->assertSame(1, $score['columns']['vector']['ran']);   // only coded rows the mechanisms saw
    }

    public function test_the_scorer_still_scores_the_sorter_alone_on_every_row(): void
    {
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => self::MECH]);
        $rows = [
            // [text, heading, service, type, sorter kind, p(trash)]
            ['Coffee beans', '0901', false, 'good', 'good', 0.01],
            ['Consulting', '99', true, 'service', 'service', 0.01],
            ['GrandMart 9 AVM Binə', null, false, 'trash', 'trash', 0.995],   // sure → trash ✓
            ['Market', null, false, 'trash', 'trash', 0.9],                   // top trash, below → unsure
            ['12345', null, false, 'good', 'good', 0.01],                     // a rule fires on a GOOD row
        ];
        $run = TestRun::create(['test_dataset_id' => $dataset->id, 'description' => 'r', 'batch' => 'b', 'mechanisms' => self::MECH, 'config' => [], 'status' => 'running', 'total' => count($rows)]);
        foreach ($rows as $i => [$text, $heading, $service, $type, $kind, $pTrash]) {
            $row = $dataset->rows()->create(['source_text' => $text, 'expected_heading' => $heading, 'expected_is_service' => $service, 'expected_type' => $type]);
            $item = ClassificationItem::create(['batch' => 'b', 'test_run_id' => $run->id, 'test_dataset_row_id' => $row->id, 'source_text' => $text, 'source_hash' => "h{$i}", 'resolution' => $heading ? 'agreed' : 'no_match', 'final_code' => $heading]);
            $item->results()->create(['mechanism' => 'sorter', 'kind' => $kind, 'status' => 'sorted', 'trace' => ['probs' => ['good' => 0, 'service' => 0, 'trash' => $pTrash], 'trash_threshold' => 0.99]]);
        }

        $score = app(RunScorer::class)->score($run);

        $this->assertSame(['ran' => 5, 'answered' => 4, 'correct' => 4], $score['columns']['sorter']);
        $this->assertSame(['good' => ['good' => 2], 'service' => ['service' => 1], 'trash' => ['trash' => 1, 'unsure' => 1]], $score['sorter']['confusion']);
        $this->assertSame(['tp' => 1, 'fp' => 1, 'fn' => 1], $score['sorter']['with_rules']);   // "12345" is a rule's false alarm
    }

    public function test_the_run_page_shows_the_trash_step_and_the_sorter_block(): void
    {
        $this->actingAs(User::factory()->create());
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => self::MECH]);
        $run = TestRun::create([
            'test_dataset_id' => $dataset->id, 'description' => 'r', 'batch' => 'b', 'mechanisms' => self::MECH, 'config' => [],
            'status' => 'done', 'total' => 4, 'started_at' => now(), 'finished_at' => now(),
            'accuracy' => [
                'columns' => ['sorter' => ['ran' => 4, 'answered' => 4, 'correct' => 3], 'overall' => ['ran' => 4, 'answered' => 4, 'correct' => 3]], 'total' => 4, 'tokens' => 0,
                'funnel' => ['total' => 1, 'prevote' => [1 => ['ran' => 0, 'answered' => 0, 'correct' => 0, 'promoted' => 0]], 'search_by_origin' => []],
                'sorter' => ['threshold' => 0.99747, 'confusion' => ['good' => ['good' => 2], 'trash' => ['trash' => 1, 'good' => 1]], 'with_rules' => ['tp' => 2, 'fp' => 0, 'fn' => 0]],
                'trash' => ['removed' => 3, 'right' => 2, 'by_rules' => 1, 'by_sorter' => 2, 'trash_rows' => 2],
            ],
        ]);

        Livewire::test(TestingRun::class, ['run' => $run])
            ->assertOk()
            ->assertSee(__('Trash filter (rules :rules · sorter :sorter) — caught :caught of :rows trash rows', ['rules' => 1, 'sorter' => 2, 'caught' => 2, 'rows' => 2]))
            ->assertSee('67%')   // the step's precision: 2 of the 3 rows it took out
            ->assertSee(__('Trash · rules + sorter'))
            ->assertSee('0.99747')
            ->assertSee('75%');  // the sorter's kind correct: 3 of 4
    }
}
