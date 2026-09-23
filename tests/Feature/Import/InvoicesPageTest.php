<?php

namespace Tests\Feature\Import;

use App\Livewire\Invoices;
use App\Models\ClassificationItem;
use App\Models\EInvoice;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class InvoicesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_line_rows_show_the_item_the_classified_code_and_the_declared_one(): void
    {
        $item = ClassificationItem::create(['batch' => 'b', 'source_text' => 'K71364 Divan', 'source_hash' => 'h1', 'resolution' => 'agreed', 'final_code' => '9401', 'kind' => 'good']);
        EInvoice::create(['item_name' => 'K71364 Divan', 'unit' => 'ədəd', 'quantity' => 2, 'declared_code' => '9403301100', 'total_amount' => 2867, 'classification_item_id' => $item->id]);
        EInvoice::create(['series' => 'MT', 'number' => '1', 'invoice_key' => 'MT|1', 'supplier_tin' => 'A_1', 'invoice_date' => '2026-01-01', 'total_amount' => 118]);

        Livewire::actingAs(User::factory()->create())->test(Invoices::class)
            ->assertOk()
            ->assertSee('K71364 Divan')
            ->assertSee('9401')              // our classifier's heading
            ->assertSee('9403301100')        // what the supplier declared
            ->assertSee('MT·1');             // the legacy invoice row is still listed
    }

    public function test_search_matches_item_names_and_the_upload_filter_narrows_to_one_upload(): void
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();
        ImportBatch::create(['key' => $a, 'label' => 'a.xlsx', 'source' => 'invoices', 'format' => 'sablon']);
        ImportBatch::create(['key' => $b, 'label' => 'b.xlsx', 'source' => 'invoices', 'format' => 'sablon']);
        EInvoice::create(['item_name' => 'K71364 Divan', 'import_batch' => $a, 'total_amount' => 1]);
        EInvoice::create(['item_name' => 'Asus Notebook', 'import_batch' => $b, 'total_amount' => 2]);

        $page = Livewire::actingAs(User::factory()->create())->test(Invoices::class);

        $page->set('q', 'divan')->assertSee('K71364 Divan')->assertDontSee('Asus Notebook');
        $page->set('q', '')->set('upload', $b)->assertSee('Asus Notebook')->assertDontSee('K71364 Divan');
    }
}
