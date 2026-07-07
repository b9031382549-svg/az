<?php

namespace Tests\Feature\Classify;

use App\Livewire\Benchmark;
use App\Models\ClassificationItem;
use App\Models\GoldLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BenchmarkPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_and_filters(): void
    {
        GoldLabel::create(['source' => 'ivan', 'name' => 'Kateter', 'name_key' => GoldLabel::keyFor('Kateter'), 'code' => '9018390000', 'heading' => '9018', 'is_service' => false]);
        ClassificationItem::create(['batch' => 'b', 'source_text' => 'Kateter', 'source_hash' => 'h', 'final_code' => '9018319000', 'kind' => 'good', 'resolution' => 'conflict']);

        Livewire::actingAs(User::factory()->create())
            ->test(Benchmark::class)
            ->assertOk()
            ->call('setStatus', 'disagree')
            ->assertSee('Kateter')
            ->call('setSource', 'fedor')
            ->assertOk();
    }
}
