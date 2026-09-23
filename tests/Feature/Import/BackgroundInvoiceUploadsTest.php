<?php

namespace Tests\Feature\Import;

use App\Jobs\AnalyzeInvoiceUploadJob;
use App\Jobs\ClassifyMechanismJob;
use App\Jobs\FeedUploadClassificationJob;
use App\Jobs\ImportInvoiceUploadJob;
use App\Jobs\TranslateItemJob;
use App\Livewire\UploadInvoices;
use App\Models\ClassificationItem;
use App\Models\EInvoice;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Import\BackgroundInvoiceUploads;
use App\Services\Import\InvoiceLinesImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BackgroundInvoiceUploadsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->dir = sys_get_temp_dir().'/az-uploads-'.bin2hex(random_bytes(4));
        config()->set('uploads.directory', $this->dir);
        config()->set('uploads.slice_lines', 2);       // several portions even for a tiny file
        config()->set('uploads.feed_portion', 2);
        config()->set('classify.mechanisms.enabled', ['vector']);
        config()->set('classify.translate_items', false);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function uploads(): BackgroundInvoiceUploads
    {
        return app(BackgroundInvoiceUploads::class);
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'big').'.xlsx';
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray(array_merge([InvoiceLinesImporterTest::HEADER], $rows), null, 'A1', true);
        (new Xlsx($book))->save($path);

        return $path;
    }

    private function batch(string $key): ImportBatch
    {
        return ImportBatch::where('key', $key)->sole();
    }

    /** Start + read a file to its preview, the way the worker would. */
    private function analyzed(string $path, string $ext = 'xlsx'): ImportBatch
    {
        $batch = $this->uploads()->start($path, 'big.'.$ext, $ext, null);
        $this->uploads()->analyze($batch->key);

        return $batch->fresh();
    }

    public function test_a_big_export_is_read_previewed_imported_and_classified_in_portions(): void
    {
        $path = $this->xlsx([
            InvoiceLinesImporterTest::line(['item' => 'Divan', 'series' => 'MT', 'number' => '1']),
            InvoiceLinesImporterTest::line(['item' => 'Yan masa', 'series' => 'MT', 'number' => '1']),
            InvoiceLinesImporterTest::line(['item' => 'divan', 'series' => 'MT', 'number' => '2']),
            InvoiceLinesImporterTest::line(['item' => 'Noutbuk']),
            InvoiceLinesImporterTest::line(['item' => 'Oturacaq']),
        ]);

        $batch = $this->uploads()->start($path, 'big.xlsx', 'xlsx', null);
        $this->assertSame('analyzing', $batch->status);
        Queue::assertPushed(AnalyzeInvoiceUploadJob::class, fn ($j) => $j->batch === $batch->key);

        $this->uploads()->analyze($batch->key);
        $batch->refresh();

        // The very same preview a small file gets in the request.
        $this->assertSame('ready', $batch->status);
        $this->assertSame('sablon', $batch->format);
        $preview = $batch->meta['preview'];
        $this->assertTrue($preview['ok']);
        $this->assertSame(5, $preview['count']);
        $this->assertSame(2, $preview['stats']['invoices']);
        $this->assertSame(2, $preview['stats']['unidentified_lines']);
        $this->assertSame(4, $preview['stats']['unique_items']);
        $this->assertCount(5, $preview['sample']);

        $this->assertTrue($this->uploads()->startImport($batch, false));
        Queue::assertPushed(ImportInvoiceUploadJob::class);

        $this->uploads()->import($batch->key);   // 3 portions of 2 lines, one run
        $batch->refresh();

        $this->assertSame('imported', $batch->status);
        $this->assertSame(5, EInvoice::where('import_batch', $batch->key)->count());
        $this->assertSame(4, ClassificationItem::where('batch', $batch->key)->count());
        $this->assertSame(0, EInvoice::whereNull('classification_item_id')->count());
        $this->assertSame(4, $batch->item_count);
        $this->assertSame(['lines' => 5, 'invoices' => 2, 'unidentified_lines' => 2, 'skipped' => 0], $batch->stats);
        $this->assertSame(5, $batch->meta['report']['imported']);
        $this->assertSame([], File::files($this->dir));   // the file and its NDJSON are gone

        // Classification is fed in portions of 2 items, never all at once.
        Queue::assertNotPushed(ClassifyMechanismJob::class);
        $this->uploads()->feed($batch->key);
        Queue::assertPushed(ClassifyMechanismJob::class, 2);
        $this->uploads()->feed($batch->key);
        $this->uploads()->feed($batch->key);
        Queue::assertPushed(ClassifyMechanismJob::class, 4);
        $this->uploads()->feed($batch->key);             // nothing left
        Queue::assertPushed(ClassifyMechanismJob::class, 4);
        $this->assertSame(ClassificationItem::where('batch', $batch->key)->max('id'), $batch->fresh()->fed_until_id);
    }

    public function test_the_import_resumes_from_its_offset_when_its_time_is_up(): void
    {
        $rows = array_map(fn ($i) => InvoiceLinesImporterTest::line(['item' => "Item {$i}"]), range(1, 5));
        $batch = $this->analyzed($this->xlsx($rows));
        config()->set('uploads.job_seconds', 0);   // one portion per import run
        $this->uploads()->startImport($batch, false);

        $this->uploads()->import($batch->key);
        $this->assertSame(2, EInvoice::count());
        $this->assertSame('importing', $batch->fresh()->status);
        Queue::assertPushed(ImportInvoiceUploadJob::class, 2);   // start + its continuation

        $this->uploads()->import($batch->key);
        $this->uploads()->import($batch->key);

        $this->assertSame(5, EInvoice::count());
        $this->assertSame('imported', $batch->fresh()->status);
        $this->uploads()->import($batch->key);                    // a stray re-run adds nothing
        $this->assertSame(5, EInvoice::count());
    }

    public function test_a_second_reader_of_the_same_file_backs_off(): void
    {
        $batch = $this->uploads()->start($this->xlsx([InvoiceLinesImporterTest::line()]), 'big.xlsx', 'xlsx', null);
        $lock = Cache::lock('invoice-upload-analyze:'.$batch->key, 60);
        $lock->get();   // a reading in progress (e.g. the copy the queue re-released after retry_after)

        $this->assertFalse($this->uploads()->analyze($batch->key));
        $this->assertSame('analyzing', $batch->fresh()->status);

        $lock->release();
        $this->assertTrue($this->uploads()->analyze($batch->key));
        $this->assertSame('ready', $batch->fresh()->status);
    }

    public function test_skip_duplicates_never_counts_the_uploads_own_earlier_portions(): void
    {
        // An earlier upload already holds invoice MT|9.
        EInvoice::create(['series' => 'MT', 'number' => '9', 'invoice_key' => 'MT|9', 'total_amount' => 1]);

        $batch = $this->analyzed($this->xlsx([
            InvoiceLinesImporterTest::line(['item' => 'A', 'series' => 'MT', 'number' => '1']),
            InvoiceLinesImporterTest::line(['item' => 'B', 'series' => 'MT', 'number' => '1']),
            InvoiceLinesImporterTest::line(['item' => 'C', 'series' => 'MT', 'number' => '1']),   // same invoice, next portion
            InvoiceLinesImporterTest::line(['item' => 'D', 'series' => 'MT', 'number' => '9']),   // already loaded
        ]));
        $this->assertSame(1, $batch->meta['preview']['duplicates']);

        $this->uploads()->startImport($batch, true);
        $this->uploads()->import($batch->key);

        $this->assertSame(3, EInvoice::where('import_batch', $batch->key)->count());
        $this->assertSame(1, $batch->fresh()->meta['report']['skipped']);
    }

    public function test_a_big_invoice_list_streams_from_csv_and_keeps_first_occurrences(): void
    {
        // Excel in AZ/RU locales saves ';'-separated csv with a BOM.
        $path = tempnam(sys_get_temp_dir(), 'legacy').'.csv';
        $rows = [
            ['No.', 'Supplier TIN', 'Recipient TIN', 'Date', 'Approval', 'Series', 'Number', 'Excise', 'VAT-taxable', 'Non-VAT', 'Exempt', 'Zero', 'VAT', 'Road', 'Total'],
            [1, 'A_1', 'T_1', '2026-01-01', '2026-01-02', 'MT', '1', 0, 100, 0, 0, 0, 18, 0, 118],
            [2, 'A_1', 'T_1', '2026-01-01', '2026-01-02', 'MT', '2', 0, 100, 0, 0, 0, 18, 0, 118],
            [3, 'A_1', 'T_1', '2026-01-01', '2026-01-02', 'MT', '1', 0, 100, 0, 0, 0, 18, 0, 118],   // repeat of MT|1
        ];
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", array_map(fn ($r) => implode(';', $r), $rows))."\n");

        $batch = $this->analyzed($path, 'csv');
        $this->assertSame('legacy', $batch->format);
        $this->assertSame(3, $batch->meta['preview']['count']);
        $this->assertSame(1, $batch->meta['preview']['duplicates']);

        $this->uploads()->startImport($batch, true);
        $this->uploads()->import($batch->key);

        $this->assertSame(['MT|1', 'MT|2'], EInvoice::orderBy('id')->pluck('invoice_key')->all());
        $this->assertSame('2026-01-01', EInvoice::first()->invoice_date->toDateString());
        Queue::assertNotPushed(FeedUploadClassificationJob::class);   // nothing to classify
    }

    public function test_feeding_waits_while_the_queue_is_deep(): void
    {
        config()->set('uploads.feed_high_water', 3);
        $batch = $this->analyzed($this->xlsx([InvoiceLinesImporterTest::line(['item' => 'Divan'])]));
        $this->uploads()->startImport($batch, false);
        $this->uploads()->import($batch->key);

        // The queue already holds 3+ jobs (the import's own dispatches plus other work).
        dispatch(new TranslateItemJob('x'));
        dispatch(new TranslateItemJob('y'));
        dispatch(new TranslateItemJob('z'));
        $this->uploads()->feed($batch->key);

        Queue::assertNotPushed(ClassifyMechanismJob::class);
        $this->assertNull($batch->fresh()->fed_until_id);
        Queue::assertPushed(FeedUploadClassificationJob::class, fn ($j) => $j->delay !== null);
    }

    public function test_tend_resumes_stalled_work_fails_dead_reads_and_prunes_old_previews(): void
    {
        $importing = ImportBatch::create(['key' => (string) Str::uuid(), 'label' => 'i', 'source' => 'invoices', 'status' => 'importing', 'meta' => []]);
        $reading = $this->uploads()->start($this->xlsx([InvoiceLinesImporterTest::line()]), 'r.xlsx', 'xlsx', null);
        $reading->update(['meta' => ['file' => $reading->meta['file'], 'read' => 5000]]);   // started, then went silent
        $queued = $this->uploads()->start($this->xlsx([InvoiceLinesImporterTest::line()]), 'q.xlsx', 'xlsx', null);
        $old = $this->analyzed($this->xlsx([InvoiceLinesImporterTest::line()]));
        ImportBatch::whereKey([$importing->id, $reading->id, $queued->id])->update(['updated_at' => now()->subMinutes(30)]);
        ImportBatch::whereKey($old->id)->update(['updated_at' => now()->subDays(2)]);

        $this->uploads()->tend();

        Queue::assertPushed(ImportInvoiceUploadJob::class, fn ($j) => $j->batch === $importing->key);
        $this->assertSame('failed', $reading->fresh()->status);
        $this->assertSame('analyzing', $queued->fresh()->status);        // still waiting in the queue
        $this->assertNull(ImportBatch::find($old->id));                 // a preview nobody imported
        $this->assertFalse(is_file($this->dir.'/'.$old->key.'.ndjson'));
    }

    public function test_the_page_hands_a_big_file_to_the_background_and_follows_it(): void
    {
        config()->set('uploads.background_bytes', 1);
        $bytes = file_get_contents($this->xlsx([
            InvoiceLinesImporterTest::line(['item' => 'Divan']),
            InvoiceLinesImporterTest::line(['item' => 'Noutbuk']),
            InvoiceLinesImporterTest::line(['item' => 'Oturacaq']),
        ]));

        $page = Livewire::actingAs(User::factory()->create())->test(UploadInvoices::class)
            ->set('file', UploadedFile::fake()->createWithContent('Şablon.xlsx', $bytes));

        $key = $page->get('pending');
        $this->assertNotNull($key);
        $page->assertSee('Reading the file');
        $this->assertSame(0, EInvoice::count());

        // The worker reads it → the page shows the usual preview.
        $this->uploads()->analyze($key);
        $page->call('refreshPending')
            ->assertSet('preview.format', 'sablon')
            ->assertSet('preview.count', 3)
            ->assertSee('Divan');

        // Import → the page follows the import, then the classification.
        $page->call('import')->assertSet('preview', null)->assertSee('Importing');
        $this->uploads()->import($key);
        $page->call('refreshPending')
            ->assertSet('report.imported', 3)
            ->assertSet('queued.count', 3)
            ->assertSet('pending', null)
            ->assertSee('Classifying');
    }

    public function test_coming_back_to_the_page_picks_up_the_upload_in_flight_and_cancel_removes_it(): void
    {
        $user = User::factory()->create();
        $batch = $this->uploads()->start($this->xlsx([InvoiceLinesImporterTest::line()]), 'big.xlsx', 'xlsx', $user->id);

        $page = Livewire::actingAs($user)->test(UploadInvoices::class)->assertSet('pending', $batch->key);

        $page->call('startOver')->assertSet('pending', null);
        $this->assertNull(ImportBatch::find($batch->id));
        $this->assertSame([], File::files($this->dir));
    }

    public function test_small_and_old_binary_files_stay_in_the_request(): void
    {
        $this->assertFalse(BackgroundInvoiceUploads::wants('xlsx', 1024));
        $this->assertTrue(BackgroundInvoiceUploads::wants('xlsx', 6 * 1024 * 1024));
        $this->assertTrue(BackgroundInvoiceUploads::wants('csv', 6 * 1024 * 1024));
        $this->assertFalse(BackgroundInvoiceUploads::wants('xls', 20 * 1024 * 1024));
        $this->assertSame(InvoiceLinesImporter::MAX_LINES, 20000);
    }
}
