<?php

namespace Tests\Feature\Classify;

use App\Models\AnswerCache;
use App\Models\ClassificationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The data fix for chapter-99 headings stored as goods (the ensemble resolver and the
// reviewer's 4-digit confirm): items, the ensemble's trace rows and memory become services.
class ChapterNinetyNineFixTest extends TestCase
{
    use RefreshDatabase;

    public function test_chapter_99_codes_stored_as_goods_become_services(): void
    {
        $bad = ClassificationItem::create(['batch' => 'b', 'source_text' => 'Market', 'source_hash' => 'h1', 'resolution' => 'ai_resolved', 'final_code' => '9966', 'kind' => 'good']);
        $bad->results()->create(['mechanism' => 'ensemble', 'matched_code' => '9966', 'kind' => 'good', 'status' => 'auto_confirmed']);
        $good = ClassificationItem::create(['batch' => 'b', 'source_text' => 'Noutbuk', 'source_hash' => 'h2', 'resolution' => 'agreed', 'final_code' => '8471', 'kind' => 'good']);
        $memory = AnswerCache::create(['source' => 'confirmed', 'name' => 'GRISHAM SERVICES', 'name_key' => AnswerCache::keyFor('GRISHAM SERVICES'), 'heading' => '9970', 'is_service' => false]);

        (require database_path('migrations/2026_10_01_100000_fix_chapter_99_codes_stored_as_goods.php'))->up();

        $this->assertSame('service', $bad->fresh()->kind);
        $this->assertSame('9966', $bad->fresh()->final_code);   // the heading itself is kept
        $this->assertSame('service', $bad->results()->where('mechanism', 'ensemble')->first()->kind);
        $this->assertSame('good', $good->fresh()->kind);
        $this->assertTrue((bool) $memory->fresh()->is_service);
        $this->assertNull($memory->fresh()->heading);
    }
}
