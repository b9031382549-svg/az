<?php

namespace Tests\Feature\Classify;

use App\Jobs\ClassifyMechanismJob;
use App\Jobs\TranslateItemJob;
use App\Models\AnswerCache;
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

    public function test_a_name_that_names_no_product_is_trash_with_no_ai(): void
    {
        Queue::fake();
        config()->set('classify.mechanisms.enabled', ['vector', 'direct']);
        config()->set('classify.translate_items', true);

        app(ClassificationQueue::class)->enqueue(['Müqaviləyə əsasən', 'Noutbuk'], 'b3');

        $trash = ClassificationItem::where('source_text', 'Müqaviləyə əsasən')->first();
        $this->assertSame('trash', $trash->resolution);
        $this->assertNull($trash->final_code);
        $this->assertNotNull($trash->answered_at);   // settled at once
        $trace = $trash->results()->where('mechanism', 'trash')->first();
        $this->assertSame('paperwork', $trace->trace['rule']);
        $this->assertSame('trash', $trace->status);

        // Only the real item goes to the mechanisms; both names still get a translation.
        Queue::assertPushed(ClassifyMechanismJob::class, 2);
        Queue::assertPushed(ClassifyMechanismJob::class, fn ($job) => $job->itemId !== $trash->id);
        Queue::assertPushed(TranslateItemJob::class, 2);
    }

    public function test_a_verified_answer_in_memory_wins_over_the_trash_filter(): void
    {
        Queue::fake();
        AnswerCache::create(['source' => 'fedor', 'name' => 'Bəyannamə', 'name_key' => AnswerCache::keyFor('Bəyannamə'), 'heading' => null, 'is_service' => true]);

        app(ClassificationQueue::class)->enqueue(['Bəyannamə'], 'b4');

        $item = ClassificationItem::where('batch', 'b4')->first();
        $this->assertSame('agreed', $item->resolution);
        $this->assertSame('99', $item->final_code);
        $this->assertFalse($item->results()->where('mechanism', 'trash')->exists());
    }

    public function test_classify_anyway_sends_a_trash_item_to_the_ai_and_it_is_never_re_trashed(): void
    {
        Queue::fake();
        config()->set('classify.mechanisms.enabled', ['vector', 'direct']);
        $queue = app(ClassificationQueue::class);
        $queue->enqueue(['POLAR BOYA MMC'], 'b5');
        $item = ClassificationItem::where('batch', 'b5')->first();
        Queue::assertNotPushed(ClassifyMechanismJob::class);

        $this->assertTrue($queue->classifyAnyway($item));

        $item->refresh();
        $this->assertSame('pending', $item->resolution);
        $this->assertNull($item->answered_at);        // the pipeline starts over
        $this->assertSame('overridden', $item->results()->where('mechanism', 'trash')->value('status'));
        Queue::assertPushed(ClassifyMechanismJob::class, 2);

        // A later dispatch of the same item (a re-feed) must not flip the reviewer's call back.
        $queue->dispatch(collect([$item]));
        $this->assertSame('pending', $item->fresh()->resolution);
        Queue::assertPushed(ClassifyMechanismJob::class, 4);

        // Only a trash item can be sent this way.
        $this->assertFalse($queue->classifyAnyway($item->fresh()));
    }

    public function test_a_decided_item_is_never_turned_into_trash(): void
    {
        Queue::fake();
        $item = ClassificationItem::create([
            'batch' => 'b6', 'source_text' => '646', 'source_hash' => ItemTranslation::hashFor('646'),
            'resolution' => 'confirmed', 'final_code' => '8471', 'kind' => 'good',
        ]);

        app(ClassificationQueue::class)->dispatch(collect([$item]));

        $this->assertSame('confirmed', $item->fresh()->resolution);
        $this->assertFalse($item->results()->where('mechanism', 'trash')->exists());
    }
}
