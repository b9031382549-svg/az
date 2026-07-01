<?php

namespace Tests\Feature\Classify;

use App\Jobs\ClassifyMechanismJob;
use App\Jobs\TranslateItemJob;
use App\Livewire\Classify;
use App\Models\ClassificationItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ClassifyDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_run_creates_items_and_fans_out_one_job_per_mechanism(): void
    {
        Queue::fake();
        config()->set('classify.mechanisms.enabled', ['vector']);
        config()->set('classify.translate_items', true);

        Livewire::actingAs(User::factory()->create())
            ->test(Classify::class)
            ->set('input', "noutbuk\ntelevizor")
            ->call('run');

        $this->assertSame(2, ClassificationItem::where('batch', '!=', 'x')->count());
        $this->assertSame('pending', ClassificationItem::first()->resolution);
        Queue::assertPushed(ClassifyMechanismJob::class, 2);   // 2 items x 1 mechanism
        Queue::assertPushed(TranslateItemJob::class, 2);
    }

    public function test_two_enabled_mechanisms_dispatch_two_jobs_per_item(): void
    {
        Queue::fake();
        config()->set('classify.mechanisms.enabled', ['vector', 'broker']);
        config()->set('classify.translate_items', false);

        Livewire::actingAs(User::factory()->create())
            ->test(Classify::class)
            ->set('input', 'noutbuk')
            ->call('run');

        $this->assertSame(1, ClassificationItem::count());
        Queue::assertPushed(ClassifyMechanismJob::class, 2);   // 1 item x 2 mechanisms
        Queue::assertNotPushed(TranslateItemJob::class);
    }

    public function test_duplicate_lines_collapse_to_one_item(): void
    {
        Queue::fake();
        config()->set('classify.mechanisms.enabled', ['vector']);

        Livewire::actingAs(User::factory()->create())
            ->test(Classify::class)
            ->set('input', "noutbuk\nnoutbuk")
            ->call('run');

        $this->assertSame(1, ClassificationItem::count());
    }
}
