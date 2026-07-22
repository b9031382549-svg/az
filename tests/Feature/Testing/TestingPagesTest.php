<?php

namespace Tests\Feature\Testing;

use App\Jobs\ClassifyTestItemMechanismJob;
use App\Jobs\ScoreRunJob;
use App\Livewire\Testing;
use App\Livewire\TestingCompare;
use App\Livewire\TestingDataset;
use App\Livewire\TestingRun;
use App\Models\TestDataset;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Testing\DatasetMemory;
use App\Services\Testing\TestRunner;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class TestingPagesTest extends TestCase
{
    use RefreshDatabase;

    private const MECH = ['enabled' => ['vector'], 'shadow' => [], 'cache' => false, 'search' => false];

    public function test_all_testing_pages_render(): void
    {
        $this->actingAs(User::factory()->create());

        $dataset = TestDataset::create(['name' => 'Food invoices', 'mechanisms' => self::MECH]);
        $dataset->rows()->create(['source_text' => 'coffee beans', 'expected_code' => '0901', 'expected_heading' => '0901', 'expected_is_service' => false]);
        $run = $this->makeRun($dataset);

        Livewire::test(Testing::class)->assertOk()->assertSee('Food invoices');
        Livewire::test(TestingDataset::class, ['dataset' => $dataset])->assertOk()->assertSee('coffee beans');
        Livewire::test(TestingRun::class, ['run' => $run])->assertOk();
        Livewire::test(TestingCompare::class)->assertOk();
    }

    public function test_launch_fans_out_a_mechanism_job_per_row_on_the_default_queue(): void
    {
        Bus::fake();

        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => self::MECH]);
        $dataset->rows()->createMany([
            ['source_text' => 'a', 'expected_heading' => '0901', 'expected_is_service' => false],
            ['source_text' => 'b', 'expected_heading' => '0402', 'expected_is_service' => false],
            ['source_text' => 'skipme', 'skip_reason' => 'no code', 'expected_is_service' => false],
        ]);

        $run = app(TestRunner::class)->launch($dataset, 'baseline', self::MECH);

        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame('testrun:'.$run->id, $run->fresh()->batch);

        // one vector job per scorable row (2), on the SAME production queue (not 'testing')
        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 2
            && ($batch->options['queue'] ?? null) !== 'testing'
            && $batch->jobs->every(fn ($j) => $j instanceof ClassifyTestItemMechanismJob));
    }

    public function test_a_cache_hit_row_is_not_fanned_out(): void
    {
        Bus::fake();

        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => self::MECH]);
        $dataset->rows()->create(['source_text' => 'coffee', 'expected_code' => '0901', 'expected_heading' => '0901', 'expected_is_service' => false]);
        app(DatasetMemory::class)->seedFromLabels($dataset);

        app(TestRunner::class)->launch($dataset, 'mem', ['enabled' => ['vector'], 'shadow' => [], 'cache' => true, 'search' => false]);

        // every row hit the cache → no mechanism batch, just the scorer
        Bus::assertNothingBatched();
        Bus::assertDispatched(ScoreRunJob::class);
    }

    public function test_run_page_shows_a_positive_formatted_duration_and_tokens(): void
    {
        $this->actingAs(User::factory()->create());
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => self::MECH]);
        $start = now()->subSeconds(90);
        $run = TestRun::create([
            'test_dataset_id' => $dataset->id, 'description' => 'r', 'batch' => 'testrun:x',
            'mechanisms' => self::MECH, 'config' => [], 'status' => 'done', 'total' => 0,
            'started_at' => $start, 'finished_at' => $start->copy()->addSeconds(90),
            'accuracy' => ['columns' => [], 'total' => 0, 'tokens' => 12345],
        ]);

        Livewire::test(TestingRun::class, ['run' => $run])
            ->assertOk()
            ->assertSee('1m 30s')  // positive + formatted (not "-90s" or a "0s" rollover)
            ->assertSee('12 345'); // tokens
    }

    public function test_dataset_page_renders_the_accuracy_chart_once_a_run_is_done(): void
    {
        $this->actingAs(User::factory()->create());
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => self::MECH]);
        TestRun::create([
            'test_dataset_id' => $dataset->id, 'description' => 'baseline', 'batch' => 'testrun:z',
            'mechanisms' => self::MECH, 'status' => 'done', 'total' => 2,
            'config' => [],
            'accuracy' => ['columns' => ['overall' => ['ran' => 2, 'answered' => 2, 'correct' => 1], 'vector' => ['ran' => 2, 'answered' => 2, 'correct' => 1]], 'total' => 2, 'tokens' => 100],
        ]);

        Livewire::test(TestingDataset::class, ['dataset' => $dataset])
            ->assertOk()
            ->assertSee('Accuracy by run');
    }

    private function makeRun(TestDataset $dataset): TestRun
    {
        $run = TestRun::create([
            'test_dataset_id' => $dataset->id, 'description' => 'baseline', 'batch' => 'tmp',
            'mechanisms' => self::MECH, 'config' => [], 'status' => 'running', 'total' => 1,
        ]);
        $run->update(['batch' => TestRun::batchKey($run->id)]);

        return $run;
    }
}
