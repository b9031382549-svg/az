<?php

namespace Tests\Feature\Classify;

use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Proves the new parent/child schema migrates and behaves on sqlite (the test
// driver) and that its casts/relations/idempotency guard hold.
class ClassificationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_and_results_persist_with_casts_and_relations(): void
    {
        $item = ClassificationItem::create([
            'batch' => 'b1',
            'source_text' => 'Dell noutbuk',
            'source_hash' => str_repeat('a', 64),
            'kind' => 'good',
            'resolution' => 'pending',
        ]);
        $item->results()->create([
            'mechanism' => 'vector',
            'matched_code' => '8471300000',
            'confidence' => 0.9,
            'status' => 'auto_confirmed',
            'candidates' => [['code' => '8471300000']],
            'path' => [['level' => 1, 'code' => '84']],
            'usage' => ['total_tokens' => 5],
            'tier' => 2,
        ]);

        $fresh = ClassificationItem::with('results')->first();
        $result = $fresh->results->first();

        $this->assertCount(1, $fresh->results);
        $this->assertIsArray($result->candidates);
        $this->assertIsArray($result->path);
        $this->assertIsArray($result->usage);
        $this->assertSame(2, $result->tier);
        $this->assertSame(0.9, $result->confidence);
        $this->assertSame($item->id, $result->item->id);
    }

    public function test_result_mechanism_is_unique_per_item(): void
    {
        $item = ClassificationItem::create([
            'batch' => 'b1', 'source_text' => 'x', 'source_hash' => str_repeat('b', 64),
        ]);
        $item->results()->create(['mechanism' => 'vector']);

        $this->expectException(QueryException::class);
        $item->results()->create(['mechanism' => 'vector']);
    }

    public function test_catalog_table_is_usable_on_sqlite(): void
    {
        CatalogCode::create([
            'code' => '8471300000', 'name' => 'noutbuk', 'kind' => 'good',
            'chapter' => '84', 'position' => '8471', 'subposition' => '847130', 'is_active' => true,
        ]);

        $this->assertSame('good', CatalogCode::first()->kind);
    }
}
