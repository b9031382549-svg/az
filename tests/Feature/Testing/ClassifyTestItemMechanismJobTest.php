<?php

namespace Tests\Feature\Testing;

use App\Jobs\ClassifyTestItemMechanismJob;
use App\Models\ClassificationItem;
use App\Models\TestDataset;
use App\Models\TestRun;
use App\Services\Classify\Mechanisms\ClassifierMechanism;
use App\Services\Classify\Mechanisms\MechanismResult;
use App\Services\Classify\Mechanisms\VectorMechanism;
use App\Services\Testing\TestRunFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ClassifyTestItemMechanismJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mechanism_error_is_caught_and_recorded_not_thrown(): void
    {
        $item = $this->item(['vector']);
        $this->bindVector(fn () => throw new RuntimeException('llm down'));

        // Must NOT throw — the error is handled on the success path (never reaches failed()).
        (new ClassifyTestItemMechanismJob($item->id, 'vector'))->handle(app(TestRunFinalizer::class));

        $row = $item->results()->where('mechanism', 'vector')->first();
        $this->assertNotNull($row);
        $this->assertSame('error', $row->status);
        $this->assertNull($row->matched_code);
        $this->assertSame('no_match', $item->fresh()->resolution); // lone abstention → no coded result
    }

    public function test_a_successful_mechanism_stores_its_row_and_reconciles(): void
    {
        $item = $this->item(['vector']);
        $this->bindVector(fn () => new MechanismResult('0901000000', null, 'good', 0.9, 'auto_confirmed'));

        (new ClassifyTestItemMechanismJob($item->id, 'vector'))->handle(app(TestRunFinalizer::class));

        $this->assertSame('agreed', $item->fresh()->resolution);
        $this->assertSame('0901', $item->fresh()->final_code);
    }

    private function bindVector(callable $classify): void
    {
        $this->app->bind(VectorMechanism::class, fn () => new class($classify) implements ClassifierMechanism
        {
            public function __construct(private $classify) {}

            public function key(): string
            {
                return 'vector';
            }

            public function classify(string $text): MechanismResult
            {
                return ($this->classify)();
            }
        });
    }

    private function item(array $enabled): ClassificationItem
    {
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => []]);
        $run = TestRun::create([
            'test_dataset_id' => $dataset->id, 'description' => 'r',
            'mechanisms' => ['enabled' => $enabled, 'shadow' => [], 'cache' => false, 'search' => false],
            'config' => [], 'status' => 'running', 'total' => 1,
        ]);
        $run->update(['batch' => TestRun::batchKey($run->id)]);

        return ClassificationItem::create([
            'batch' => $run->batch, 'test_run_id' => $run->id, 'source_text' => 'coffee',
            'source_hash' => bin2hex(random_bytes(32)), 'resolution' => 'pending',
        ]);
    }
}
