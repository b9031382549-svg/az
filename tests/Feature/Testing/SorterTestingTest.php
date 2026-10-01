<?php

namespace Tests\Feature\Testing;

use App\Jobs\ClassifyTestItemMechanismJob;
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

// The Testing section's "Sorter" column: every row of a run gets the sorter's verdict, scored on
// the KIND of line (good / service / trash). Kind-only rows (TRASH / GOOD in column B) are scored
// for the sorter alone — no memory, no AI spent on them — and the code columns never see them.
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
            ['source_text' => 'GrandMart 9 AVM Binə', 'expected_code' => 'TRASH', 'expected_is_service' => false, 'expected_type' => 'trash'],
            ['source_text' => 'Pivə 0,5', 'expected_code' => 'GOOD', 'expected_is_service' => false, 'expected_type' => 'good'],
        ]);

        return $dataset;
    }

    public function test_a_run_sorts_every_row_and_spends_no_ai_on_kind_only_rows(): void
    {
        Bus::fake();

        $run = app(TestRunner::class)->launch($this->dataset(), 'r', self::MECH);

        $ids = ClassificationItem::where('test_run_id', $run->id)->orderBy('id')->pluck('id')->all();
        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->filter(fn ($j) => $j instanceof ClassifyTestItemMechanismJob)->count() === 1
            && $batch->jobs->contains(fn ($j) => $j instanceof SortTestItemsJob && $j->itemIds === $ids && $j->kindOnly === [$ids[1], $ids[2]]));
    }

    public function test_without_the_sorter_kind_only_rows_are_settled_at_once(): void
    {
        Bus::fake();
        config()->set('classify.sorter.url', '');

        $run = app(TestRunner::class)->launch($this->dataset(), 'r', self::MECH);

        $this->assertSame(['pending', 'no_match', 'no_match'], ClassificationItem::where('test_run_id', $run->id)->orderBy('id')->pluck('resolution')->all());
        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->every(fn ($j) => $j instanceof ClassifyTestItemMechanismJob));
    }

    public function test_the_sort_job_stores_verdicts_and_settles_only_kind_only_rows(): void
    {
        Bus::fake();
        Http::fake(['sorter.test/*' => fn ($request) => Http::response([
            'labels' => ['good', 'service', 'trash'],
            'probs' => array_map(fn ($t) => $t === 'GrandMart 9 AVM Binə' ? [0.001, 0.004, 0.995] : [0.98, 0.01, 0.01], $request['texts']),
        ])]);
        $run = app(TestRunner::class)->launch($this->dataset(), 'r', self::MECH);
        [$code, $trash, $good] = ClassificationItem::where('test_run_id', $run->id)->orderBy('id')->get()->all();

        app()->call([new SortTestItemsJob([$code->id, $trash->id, $good->id], [$trash->id, $good->id]), 'handle']);

        $this->assertSame('pending', $code->fresh()->resolution);   // the AI settles it, not the sorter
        $this->assertSame('good', $code->results()->where('mechanism', 'sorter')->value('kind'));
        $this->assertSame('trash', $trash->fresh()->resolution);    // sure → trash, as prod would
        $this->assertSame('sorter', $trash->results()->where('mechanism', 'trash')->first()->trace['rule']);
        $this->assertSame('no_match', $good->fresh()->resolution);
    }

    public function test_the_scorer_scores_the_sorter_on_kinds_and_keeps_the_code_columns_clean(): void
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
            if ($heading) {
                $item->results()->create(['mechanism' => 'vector', 'matched_code' => $heading.'000000', 'kind' => $service ? 'service' : 'good', 'status' => 'auto_confirmed', 'candidates' => [['code' => $heading.'000000', 'kind' => $service ? 'service' : 'good']]]);
            }
        }

        $score = app(RunScorer::class)->score($run);

        $this->assertSame(['ran' => 5, 'answered' => 4, 'correct' => 4], $score['columns']['sorter']);
        $this->assertSame(['good' => ['good' => 2], 'service' => ['service' => 1], 'trash' => ['trash' => 1, 'unsure' => 1]], $score['sorter']['confusion']);
        $this->assertSame(['tp' => 1, 'fp' => 1, 'fn' => 1], $score['sorter']['with_rules']);   // "12345" is a rule's false alarm
        $this->assertSame(2, $score['columns']['overall']['ran']);   // kind-only rows never reach the code columns
        $this->assertSame(2, $score['columns']['vector']['ran']);
    }

    public function test_the_run_page_shows_the_sorter_block(): void
    {
        $this->actingAs(User::factory()->create());
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => self::MECH]);
        $run = TestRun::create([
            'test_dataset_id' => $dataset->id, 'description' => 'r', 'batch' => 'b', 'mechanisms' => self::MECH, 'config' => [],
            'status' => 'done', 'total' => 4, 'started_at' => now(), 'finished_at' => now(),
            'accuracy' => [
                'columns' => ['sorter' => ['ran' => 4, 'answered' => 4, 'correct' => 3]], 'total' => 4, 'tokens' => 0,
                'sorter' => ['threshold' => 0.99747, 'confusion' => ['good' => ['good' => 2], 'trash' => ['trash' => 1, 'good' => 1]], 'with_rules' => ['tp' => 2, 'fp' => 0, 'fn' => 0]],
            ],
        ]);

        Livewire::test(TestingRun::class, ['run' => $run])
            ->assertOk()
            ->assertSee(__('Trash · rules + sorter'))
            ->assertSee('0.99747')
            ->assertSee('75%');   // kind correct: 3 of 4
    }
}
