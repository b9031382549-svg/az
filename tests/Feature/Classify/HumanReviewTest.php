<?php

namespace Tests\Feature\Classify;

use App\Livewire\HumanReview;
use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Models\EInvoice;
use App\Models\ItemTranslation;
use App\Models\TestDataset;
use App\Models\TestRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HumanReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A real heading so a 4-digit confirm is accepted (position lookup in the trait).
        CatalogCode::create(['code' => '1104300000', 'name' => 'oat', 'kind' => 'good', 'position' => '1104', 'is_active' => true]);
    }

    private function open(string $resolution, string $text, string $batch = 'b'): ClassificationItem
    {
        $item = ClassificationItem::create([
            'batch' => $batch, 'source_text' => $text, 'source_hash' => bin2hex(random_bytes(16)),
            'resolution' => $resolution,
        ]);

        // A TERMINAL conflict (the web-search resolver ran and could not settle it) carries a
        // 'search' trace — without it a conflict reads as still in-flight ('resolving') and is
        // deliberately kept OUT of the human queue.
        if ($resolution === 'conflict') {
            $item->results()->create(['mechanism' => 'search', 'matched_code' => null, 'status' => 'needs_review']);
        }

        return $item;
    }

    private function acting()
    {
        return Livewire::actingAs(User::factory()->create())->test(HumanReview::class);
    }

    public function test_queue_holds_only_items_that_need_a_human(): void
    {
        $conflict = $this->open('conflict', 'diverged item');
        $noMatch = $this->open('no_match', 'unknown item');
        $agreed = $this->open('agreed', 'auto item');           // resolved — not a human's job
        $agreed->update(['final_code' => '1104']);

        // A test-run row must never leak into the production human queue.
        $dataset = TestDataset::create(['name' => 'd', 'mechanisms' => ['enabled' => ['vector']]]);
        $run = TestRun::create(['test_dataset_id' => $dataset->id, 'description' => 'r', 'batch' => 'tmp', 'mechanisms' => [], 'config' => [], 'status' => 'running', 'total' => 0]);
        ClassificationItem::create(['batch' => 'tr', 'test_run_id' => $run->id, 'source_text' => 'test item', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'conflict']);

        $ids = $this->acting()->viewData('queue')->pluck('id');

        $this->assertTrue($ids->contains($conflict->id));
        $this->assertTrue($ids->contains($noMatch->id));
        $this->assertFalse($ids->contains($agreed->id));
        $this->assertCount(2, $ids);
    }

    public function test_confirm_sets_the_code_and_drops_it_from_the_queue(): void
    {
        $item = $this->open('no_match', 'oat flakes 1kg');

        $c = $this->acting()->call('selectItem', $item->id)->call('confirm', '1104');

        $item->refresh();
        $this->assertSame('confirmed', $item->resolution);
        $this->assertSame('1104', $item->final_code);
        $this->assertFalse($c->viewData('queue')->pluck('id')->contains($item->id));
    }

    public function test_confirming_one_item_auto_confirms_identical_twins(): void
    {
        // Same normalized name → same source_hash (as the pipeline computes it).
        $hash = ItemTranslation::hashFor('Milk 1L');
        $a = ClassificationItem::create(['batch' => 'a', 'source_text' => 'Milk 1L', 'source_hash' => $hash, 'resolution' => 'no_match']);
        $b = ClassificationItem::create(['batch' => 'b', 'source_text' => 'Milk 1L', 'source_hash' => $hash, 'resolution' => 'no_match']);

        $c = $this->acting()->call('selectItem', $a->id)->call('confirm', '1104');

        $this->assertSame('confirmed', $a->fresh()->resolution);
        $this->assertSame('confirmed', $b->fresh()->resolution);   // the twin auto-confirmed
        $this->assertSame('1104', $b->fresh()->final_code);
        $this->assertSame(1, $c->get('twinsConfirmed'));
    }

    public function test_skip_leaves_the_item_untouched(): void
    {
        $a = $this->open('no_match', 'first');
        $b = $this->open('no_match', 'second');

        $c = $this->acting()->call('selectItem', $a->id)->call('skip');

        // Nothing written…
        $this->assertSame('no_match', $a->fresh()->resolution);
        // …and the selection advanced to the next item.
        $this->assertSame($b->id, $c->get('selected'));
    }

    public function test_confirming_an_invalid_code_surfaces_an_error_and_changes_nothing(): void
    {
        $item = $this->open('no_match', 'mystery item');

        $this->acting()->call('selectItem', $item->id)
            ->call('confirm', '9999')          // not 99, not an active catalog position
            ->assertHasErrors('confirm');

        $this->assertSame('no_match', $item->fresh()->resolution);
    }

    public function test_reject_marks_the_item_rejected(): void
    {
        $item = $this->open('conflict', 'nope');

        $this->acting()->call('selectItem', $item->id)->call('rejectItem');

        $this->assertSame('rejected', $item->fresh()->resolution);
    }

    public function test_page_renders_with_tiles(): void
    {
        $this->open('no_match', 'x');

        $this->acting()->assertOk()
            ->assertSee('Waiting on a human')
            ->assertSee('Processed today');
    }

    public function test_the_panel_shows_what_the_invoices_declared_for_the_item(): void
    {
        $item = $this->open('no_match', 'ÇAYDAN ARZUM TEAMOND');
        $line = fn (string $unit) => EInvoice::create([
            'item_name' => 'ÇAYDAN ARZUM TEAMOND', 'declared_code' => '3305200000',
            'declared_group' => 'Saç üçün vasitələr', 'unit' => $unit, 'classification_item_id' => $item->id,
        ]);
        $line('ƏDƏD');
        $line('ədəd');

        $page = $this->acting()->call('selectItem', $item->id);

        $hints = $page->viewData('invoiceHints');
        $this->assertSame(2, $hints['lines']);
        $this->assertSame([['code' => '3305200000', 'group' => 'Saç üçün vasitələr', 'lines' => 2]], $hints['codes']);
        $this->assertSame([['unit' => 'ədəd', 'lines' => 2]], $hints['units']);   // one unit, any case
        $page->assertSee('3305200000')->assertSee('Saç üçün vasitələr');
    }

    public function test_an_item_without_invoice_lines_has_no_invoice_hint(): void
    {
        $this->open('no_match', 'typed on the Classify page');

        $this->assertNull($this->acting()->viewData('invoiceHints'));
    }
}
