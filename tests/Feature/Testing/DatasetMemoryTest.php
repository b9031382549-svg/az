<?php

namespace Tests\Feature\Testing;

use App\Models\AnswerCache;
use App\Models\ClassificationItem;
use App\Models\TestDataset;
use App\Services\Classify\AnswerCacheService;
use App\Services\Classify\Mechanisms\ClassifierMechanism;
use App\Services\Classify\Mechanisms\MechanismResult;
use App\Services\Classify\Mechanisms\VectorMechanism;
use App\Services\Testing\DatasetMemory;
use App\Services\Testing\DatasetRowClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatasetMemoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_and_dataset_memory_are_isolated(): void
    {
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => []]);
        AnswerCache::create(['test_dataset_id' => 0, 'source' => 'prod', 'name' => 'coffee', 'name_key' => AnswerCache::keyFor('coffee'), 'heading' => '1111', 'is_service' => false]);
        AnswerCache::create(['test_dataset_id' => $dataset->id, 'source' => 'ds', 'name' => 'coffee', 'name_key' => AnswerCache::keyFor('coffee'), 'heading' => '0901', 'is_service' => false]);

        $svc = app(AnswerCacheService::class);
        $this->assertSame('1111', $svc->lookup('coffee')?->heading);             // production (default scope 0)
        $this->assertSame('0901', $svc->lookup('coffee', $dataset->id)?->heading); // this dataset only
    }

    public function test_memory_hit_short_circuits_the_pipeline_in_a_run(): void
    {
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => []]);
        $dataset->rows()->create(['source_text' => 'coffee beans', 'expected_code' => '0901', 'expected_heading' => '0901', 'expected_is_service' => false]);
        app(DatasetMemory::class)->seedFromLabels($dataset);

        // A vector mechanism that would answer WRONG — it must never run on a cache hit.
        $this->app->bind(VectorMechanism::class, fn () => new class implements ClassifierMechanism
        {
            public function key(): string
            {
                return 'vector';
            }

            public function classify(string $text): MechanismResult
            {
                return new MechanismResult('9999000000', null, 'good', 0.9, 'auto_confirmed');
            }
        });

        $item = ClassificationItem::create(['batch' => 'testrun:1', 'source_text' => 'coffee beans', 'source_hash' => bin2hex(random_bytes(32)), 'resolution' => 'pending']);
        app(DatasetRowClassifier::class)->run($item, ['enabled' => ['vector'], 'cache' => true, 'search' => false], $dataset->id);

        $this->assertNotNull($item->results()->where('mechanism', 'cache')->first());
        $this->assertNull($item->results()->where('mechanism', 'vector')->first()); // short-circuited
        $this->assertSame('agreed', $item->fresh()->resolution);
        $this->assertSame('0901', $item->fresh()->final_code);
    }

    public function test_seed_clear_and_dataset_delete_manage_bound_memory(): void
    {
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => []]);
        $dataset->rows()->create(['source_text' => 'x', 'expected_heading' => '0901', 'expected_is_service' => false]);
        $memory = app(DatasetMemory::class);

        $this->assertSame(1, $memory->seedFromLabels($dataset));
        $this->assertSame(1, AnswerCache::where('test_dataset_id', $dataset->id)->count());

        $memory->clear($dataset);
        $this->assertSame(0, AnswerCache::where('test_dataset_id', $dataset->id)->count());

        // deleting the dataset removes any bound rows (booted deleting hook)
        $memory->seedFromLabels($dataset);
        $dataset->delete();
        $this->assertSame(0, AnswerCache::where('test_dataset_id', $dataset->id)->count());
    }
}
