<?php

namespace Tests\Feature\Classify;

use App\Livewire\ReviewQueue;
use App\Models\ClassificationItem;
use App\Models\RubricatorNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// A 4-digit HS heading (from the search resolver / cache) has no exact catalog row, so
// its official name comes from the rubricator. It must be shown wherever the bare code
// appears — both the item subtitle and the per-mechanism trace row.
class HeadingNameRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_4_digit_heading_code_is_labeled_with_its_official_name(): void
    {
        RubricatorNode::create([
            'code' => '9018', 'level' => 2, 'kind' => 'good',
            'title' => 'Tibbdə, cərrahiyyədə istifadə olunan alətlər',
            'title_en' => 'Instruments used in medical sciences',
            'title_ru' => 'Приборы, используемые в медицине',
        ]);
        $item = ClassificationItem::create([
            'batch' => 'b', 'source_text' => 'Şpris 20 ml', 'source_hash' => bin2hex(random_bytes(32)),
            'kind' => 'good', 'resolution' => 'ai_resolved', 'final_code' => '9018',
        ]);
        $item->results()->create(['mechanism' => 'search', 'matched_code' => '9018', 'status' => 'auto_confirmed', 'kind' => 'good']);

        Livewire::actingAs(User::factory()->create())
            ->test(ReviewQueue::class, ['batch' => 'b'])
            ->set('filter', 'all')
            ->assertSee('9018')
            ->assertSee('Instruments used in medical sciences'); // localized (test locale=en) official heading name
    }
}
