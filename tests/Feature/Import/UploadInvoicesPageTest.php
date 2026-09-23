<?php

namespace Tests\Feature\Import;

use App\Jobs\ClassifyMechanismJob;
use App\Livewire\UploadInvoices;
use App\Models\ClassificationItem;
use App\Models\EInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class UploadInvoicesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('classify.mechanisms.enabled', ['vector']);
        config()->set('classify.translate_items', false);
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function sablon(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sablon');
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray(array_merge([InvoiceLinesImporterTest::HEADER], $rows), null, 'A1', true);
        (new Xlsx($book))->save($path);
        $bytes = file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent('Şablon.xlsx', $bytes);
    }

    private function page()
    {
        return Livewire::actingAs(User::factory()->create())->test(UploadInvoices::class);
    }

    public function test_a_line_level_export_is_previewed_imported_and_classified_at_once(): void
    {
        $file = $this->sablon([
            InvoiceLinesImporterTest::line(['item' => 'Mühafizə xidməti', 'series' => 'MT', 'number' => '1']),
            InvoiceLinesImporterTest::line(['item' => 'JBL BAR 2.1 DEEP BASS (MK2)']),
        ]);

        $page = $this->page()->set('file', $file);

        $page->assertSet('preview.format', 'sablon')
            ->assertSet('preview.ok', true)
            ->assertSet('preview.stats.invoices', 1)
            ->assertSet('preview.stats.unidentified_lines', 1)
            ->assertSee('Mühafizə xidməti');

        $page->call('import')
            ->assertSet('report.format', 'sablon')
            ->assertSet('report.imported', 2)
            ->assertSet('queued.count', 2)
            ->assertSee('Classifying…');   // the live progress panel took over

        $this->assertSame(2, EInvoice::count());
        $this->assertSame(2, ClassificationItem::count());
        Queue::assertPushed(ClassifyMechanismJob::class, 2);
    }

    public function test_the_recent_uploads_list_deletes_one_upload_and_keeps_its_classification(): void
    {
        $page = $this->page()->set('file', $this->sablon([InvoiceLinesImporterTest::line(['item' => 'Divan'])]))->call('import');
        $batch = $page->get('report.batch');

        $page->call('startOver')
            ->assertSee('Şablon.xlsx')
            ->call('deleteUpload', $batch);

        $this->assertSame(0, EInvoice::count());
        $this->assertSame(1, ClassificationItem::where('batch', $batch)->count());
        $page->assertDontSee('Şablon.xlsx');
    }

    public function test_a_file_that_is_neither_layout_is_refused_in_the_preview(): void
    {
        $page = $this->page()->set('file', UploadedFile::fake()->createWithContent('names.csv', "Malın adı\nDivan\n"));

        $page->assertSet('preview.ok', false)
            ->call('import')
            ->assertSet('report', null);
        $this->assertSame(0, EInvoice::count());
    }
}
