<?php

namespace Tests\Feature\Classify;

use App\Jobs\ClassifyMechanismJob;
use App\Jobs\TranslateItemJob;
use App\Models\ClassificationItem;
use App\Models\ItemTranslation;
use App\Services\Classify\ClassificationQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClassificationQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_many_names_are_created_in_chunks_and_keyed_by_their_hash(): void
    {
        Queue::fake();
        // More than one upsert chunk — a single upsert of ~11k+ names would break Postgres'
        // 65,535 bind-parameter cap; chunking keeps every insert small.
        $names = array_map(fn ($i) => "item {$i}", range(1, 1203));

        $items = app(ClassificationQueue::class)->createItems($names, 'b1');

        $this->assertSame(1203, ClassificationItem::where('batch', 'b1')->count());
        $this->assertSame(1203, $items->count());
        $this->assertSame('item 7', $items[ItemTranslation::hashFor('item 7')]->source_text);
        Queue::assertNothingPushed();   // creating never dispatches
    }

    public function test_enqueue_fans_out_per_mechanism_and_translates_each_name_once(): void
    {
        Queue::fake();
        config()->set('classify.mechanisms.enabled', ['vector', 'direct']);
        config()->set('classify.translate_items', true);

        $count = app(ClassificationQueue::class)->enqueue(['Divan', 'divan', 'Noutbuk'], 'b2');

        $this->assertSame(2, $count);   // "Divan" and "divan" are one item
        Queue::assertPushed(ClassifyMechanismJob::class, 4);
        Queue::assertPushed(TranslateItemJob::class, 2);
    }
}
