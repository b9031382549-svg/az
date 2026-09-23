<?php

namespace Tests\Feature\Import;

use App\Jobs\ClassifyMechanismJob;
use App\Livewire\ReviewQueue;
use App\Models\AnswerCache;
use App\Models\ClassificationItem;
use App\Models\EInvoice;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Classify\ClassificationQueue;
use App\Services\Import\InvoiceLinesImporter;
use App\Services\Import\InvoiceUploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class InvoiceLinesImporterTest extends TestCase
{
    use RefreshDatabase;

    /** The header row of the real line-level export ("Şablon"), column A has no header. */
    public const HEADER = [
        null, 'e-QF təqdim edənin vergi orqanı', 'e-QF təqdim edənin adı', 'e-QF təqdim edənin VÖEN',
        'e-QF əldə edənin Vergi orqanı', 'e-QF əldə edənin adı', 'e-QF əldə edənin VÖEN',
        'e-Qaimənin növü', 'e-Qaimənin tarixi', 'e-Qaimənin təsdiq tarixi', 'e-Qaimənin seriyası',
        'e-Qaimənin nömrəsi', 'Malın adı', 'Qrup adı', 'Kodu', 'Malın ölçü vahidi', 'Malın miqdarı',
        'Aksiz məbləği', 'ƏDV-yə cəlb edilən əməliyyatların məbləği', 'ƏDV-yə cəlb edilməyən əməliyyatların məbləği',
        'ƏDV-dən azad olunan əməliyyatların məbləği', 'ƏDV-yə "0" dərəcə ilə cəlb edilən əməliyyatların məbləği',
        'ƏDV məbləği', 'Yol vergisi', 'Yekun məbləğ',
    ];

    /** @var string[] */
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('classify.mechanisms.enabled', ['vector', 'direct']);
        config()->set('classify.translate_items', false);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /**
     * One export row in HEADER order — sensible defaults, overridable by field.
     *
     * @param  array<string, mixed>  $o
     * @return array<int, mixed>
     */
    public static function line(array $o = []): array
    {
        $v = $o + [
            'no' => 0, 'supplier_office' => 'LGBİ', 'supplier_name' => null, 'supplier_tin' => null,
            'recipient_office' => 'LGBİ', 'recipient_name' => null, 'recipient_tin' => null,
            'type' => 'Malların(işlərin,xidmətlərin) təqdim edilməsi', 'date' => '01.09.2026', 'approved' => '08.09.2026',
            'series' => null, 'number' => null, 'item' => 'JBL BAR 2.1 DEEP BASS (MK2)', 'group' => 'Mikrofonlar və reproduktorlar',
            'code' => '8518220000', 'unit' => 'ƏDƏD', 'qty' => 1, 'excise' => 0, 'vat_taxable' => 805.08, 'non_vat' => 0,
            'exempt' => 0, 'zero' => 0, 'vat' => 144.91, 'road' => 0, 'total' => 949.99,
        ];

        return [
            $v['no'], $v['supplier_office'], $v['supplier_name'], $v['supplier_tin'], $v['recipient_office'],
            $v['recipient_name'], $v['recipient_tin'], $v['type'], $v['date'], $v['approved'], $v['series'],
            $v['number'], $v['item'], $v['group'], $v['code'], $v['unit'], $v['qty'], $v['excise'],
            $v['vat_taxable'], $v['non_vat'], $v['exempt'], $v['zero'], $v['vat'], $v['road'], $v['total'],
        ];
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function xlsx(array $rows, ?array $header = null): string
    {
        $path = storage_path('app/test-sablon-'.bin2hex(random_bytes(4)).'.xlsx');
        $this->paths[] = $path;

        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray(array_merge([$header ?? self::HEADER], $rows), null, 'A1', true);
        (new Xlsx($book))->save($path);

        return $path;
    }

    private function uploads(): InvoiceUploads
    {
        return app(InvoiceUploads::class);
    }

    public function test_the_export_is_recognised_and_mapped_by_header_text_in_any_column_order(): void
    {
        // Same export, columns reversed — mapping must follow the header text, not the position.
        $row = self::line([
            'supplier_tin' => '1234567891', 'series' => 'MT2609', 'number' => '10003564',
            'item' => 'K71364 Divan', 'code' => '9403301100', 'unit' => 'ədəd', 'qty' => 2,
        ]);
        $path = $this->xlsx([array_reverse($row)], array_reverse(self::HEADER));

        $preview = $this->uploads()->preview($path);
        $this->assertSame('sablon', $preview['format']);
        $this->assertTrue($preview['ok']);

        $report = $this->uploads()->import($path, 'sablon.xlsx', null, false);
        $this->assertNull($report['error']);
        $this->assertSame(1, $report['imported']);

        $line = EInvoice::sole();
        $this->assertSame('K71364 Divan', $line->item_name);
        $this->assertSame('9403301100', $line->declared_code);
        $this->assertSame('ədəd', $line->unit);
        $this->assertSame('2.0000', $line->quantity);
        $this->assertSame('2026-09-01', $line->invoice_date->toDateString());   // text dd.mm.yyyy
        $this->assertSame('2026-09-08', $line->approval_date->toDateString());
        $this->assertSame('1234567891', $line->supplier_tin);
        $this->assertSame('LGBİ', $line->supplier_tax_office);
        $this->assertSame('MT2609|10003564', $line->invoice_key);
        $this->assertSame('949.99', $line->total_amount);
        $this->assertSame('144.91', $line->vat_amount);
        $this->assertSame($report['batch'], $line->import_batch);
    }

    public function test_the_legacy_header_is_not_the_line_level_export(): void
    {
        $this->assertFalse(InvoiceLinesImporter::matches(['No.', 'Supplier TIN', 'Recipient TIN', 'e-Invoice Date']));
        // An item list without any invoice column is not an invoice file either.
        $this->assertFalse(InvoiceLinesImporter::matches(['Malın adı', 'Qrup adı']));
        $this->assertTrue(InvoiceLinesImporter::matches(self::HEADER));
    }

    public function test_preview_counts_what_can_and_cannot_be_tied_to_an_invoice(): void
    {
        $rows = [
            self::line(['series' => 'MT', 'number' => '1', 'supplier_tin' => '111', 'item' => 'Divan']),
            self::line(['series' => 'MT', 'number' => '1', 'supplier_tin' => '111', 'item' => 'Yan masa']),
            self::line(['series' => 'MT', 'number' => '2', 'recipient_tin' => '222', 'item' => 'divan ']),
            self::line(['series' => 'MT', 'number' => null, 'item' => 'Noutbuk']),     // number missing
            self::line(['item' => null, 'date' => null]),                                // no name, no date
        ];

        $preview = app(InvoiceLinesImporter::class)->previewRows(self::HEADER, $rows, 'checksum');

        $this->assertTrue($preview['ok']);
        $this->assertSame(5, $preview['count']);
        $this->assertSame([
            'lines' => 5,
            'invoices' => 2,
            'identified_lines' => 3,
            'unidentified_lines' => 2,
            'no_supplier_tin' => 3,
            'no_recipient_tin' => 4,
            'no_date' => 1,
            'no_item' => 1,
            'unique_items' => 3,          // "Divan" and "divan " are one name
        ], $preview['stats']);
        $this->assertNull($preview['same_file']);
    }

    public function test_import_classifies_each_unique_name_once_and_links_every_line(): void
    {
        $path = $this->xlsx([
            self::line(['item' => 'Yemək porsiyaları satışı', 'qty' => 2350]),
            self::line(['item' => 'yemək  porsiyaları satışı', 'qty' => 88]),   // same name, other spelling
            self::line(['item' => 'PR xidməti', 'code' => '9963991000', 'unit' => 'ay']),
            self::line(['item' => null]),                                        // a line without a name
        ]);

        $report = $this->uploads()->import($path, 'sablon.xlsx', null, false);

        $this->assertSame(4, $report['imported']);
        $this->assertSame(2, $report['items']);
        $this->assertSame(2, ClassificationItem::where('batch', $report['batch'])->count());
        Queue::assertPushed(ClassifyMechanismJob::class, 4);   // 2 items × 2 mechanisms

        $lines = EInvoice::orderBy('row_no')->get();
        $this->assertSame($lines[0]->classification_item_id, $lines[1]->classification_item_id);
        $this->assertNotNull($lines[2]->classification_item_id);
        $this->assertNotSame($lines[0]->classification_item_id, $lines[2]->classification_item_id);
        $this->assertNull($lines[3]->classification_item_id);

        $batch = ImportBatch::where('key', $report['batch'])->sole();
        $this->assertSame('invoices', $batch->source);
        $this->assertSame('sablon', $batch->format);
        $this->assertSame('sablon.xlsx', $batch->label);
        $this->assertSame(2, $batch->item_count);
        $this->assertSame(4, $batch->stats['lines']);
        $this->assertSame(hash_file('sha256', $path), $batch->checksum);
    }

    public function test_a_name_already_in_memory_is_answered_without_the_ai(): void
    {
        AnswerCache::create(['test_dataset_id' => 0, 'source' => 'gold', 'name' => 'Noutbuk', 'name_key' => AnswerCache::keyFor('Noutbuk'), 'heading' => '8471', 'is_service' => false]);
        $path = $this->xlsx([self::line(['item' => 'Noutbuk']), self::line(['item' => 'Divan'])]);

        $report = $this->uploads()->import($path, 'f.xlsx', null, false);

        $known = ClassificationItem::where('batch', $report['batch'])->where('source_text', 'Noutbuk')->sole();
        $this->assertSame('agreed', $known->resolution);
        $this->assertSame('8471', $known->final_code);
        Queue::assertPushed(ClassifyMechanismJob::class, 2);   // only "Divan" × 2 mechanisms
    }

    public function test_skip_duplicates_skips_known_invoices_but_never_lines_without_a_number(): void
    {
        $first = $this->xlsx([self::line(['series' => 'MT', 'number' => '1', 'item' => 'Divan'])]);
        $this->uploads()->import($first, 'first.xlsx', null, false);

        $second = $this->xlsx([
            self::line(['series' => 'MT', 'number' => '1', 'item' => 'Divan']),     // invoice MT|1 is known
            self::line(['series' => 'MT', 'number' => '1', 'item' => 'Yan masa']),  // … all its lines go
            self::line(['series' => 'MT', 'number' => '2', 'item' => 'Oturacaq']),
            self::line(['item' => 'Noutbuk']),                                       // no number: kept
        ]);

        $preview = $this->uploads()->preview($second);
        $this->assertSame(2, $preview['duplicates']);
        $this->assertSame(1, $preview['duplicate_invoices']);

        $report = $this->uploads()->import($second, 'second.xlsx', null, true);
        $this->assertSame(2, $report['imported']);
        $this->assertSame(2, $report['skipped']);
        $this->assertSame(3, EInvoice::count());
        $this->assertSame(2, ClassificationItem::where('batch', $report['batch'])->count());
    }

    public function test_the_same_file_is_recognised_when_uploaded_again(): void
    {
        $path = $this->xlsx([self::line()]);
        $this->uploads()->import($path, 'sablon.xlsx', null, false);

        $preview = $this->uploads()->preview($path);

        $this->assertSame('sablon.xlsx', $preview['same_file']['label']);
    }

    public function test_numbers_excel_made_of_identifiers_come_back_as_digit_strings(): void
    {
        // A 10-digit code typed as a number loses its leading zero in Excel; a VÖEN stays whole.
        $path = $this->xlsx([self::line(['code' => 101210000, 'supplier_tin' => 1234567891, 'number' => 10003564, 'series' => 'MT'])]);

        $this->uploads()->import($path, 'f.xlsx', null, false);

        $line = EInvoice::sole();
        $this->assertSame('0101210000', $line->declared_code);
        $this->assertSame('1234567891', $line->supplier_tin);
        $this->assertSame('MT|10003564', $line->invoice_key);
    }

    public function test_a_file_over_the_line_cap_is_refused(): void
    {
        $importer = new InvoiceLinesImporter(app(ClassificationQueue::class), maxLines: 2);

        $preview = $importer->previewRows(self::HEADER, [self::line(), self::line(), self::line()], 'c');
        $this->assertFalse($preview['ok']);
        $this->assertStringContainsString('3', $preview['error']);

        $report = $importer->importRows(self::HEADER, [self::line(), self::line(), self::line()], 'c', 'f', null);
        $this->assertNotNull($report['error']);
        $this->assertSame(0, EInvoice::count());
        $this->assertSame(0, ImportBatch::count());
    }

    public function test_the_legacy_list_imports_as_before_and_is_tracked_as_an_upload(): void
    {
        $path = storage_path('app/test-legacy-'.bin2hex(random_bytes(4)).'.csv');
        $this->paths[] = $path;
        $fh = fopen($path, 'w');
        fputcsv($fh, ['No.', 'Supplier TIN', 'Recipient TIN', 'e-Invoice Date', 'e-Invoice Approval Date', 'e-Invoice Series', 'e-Invoice Number', 'Excise Amount', 'VAT-Taxable', 'Non-VAT-Taxable', 'VAT-Exempt', 'Zero-Rated', 'VAT Amount', 'Road Tax', 'Total Amount']);
        fputcsv($fh, [1, 'A_1', 'T_1', '2026-01-01', '2026-01-02', 'MT2601', '100', 0, 100, 0, 0, 0, 18, 0, 118]);
        fclose($fh);

        $this->assertSame('legacy', $this->uploads()->preview($path)['format']);
        $report = $this->uploads()->import($path, 'legacy.csv', null, false);

        $this->assertSame('legacy', $report['format']);
        $this->assertSame(1, $report['imported']);
        $row = EInvoice::sole();
        $this->assertSame('MT2601|100', $row->invoice_key);
        $this->assertSame($report['batch'], $row->import_batch);
        $this->assertNull($row->item_name);
        $this->assertSame('legacy', ImportBatch::where('key', $report['batch'])->value('format'));
        $this->assertSame(0, ClassificationItem::count());
        Queue::assertNothingPushed();
    }

    public function test_deleting_an_upload_removes_its_lines_but_keeps_its_classification(): void
    {
        $keep = $this->uploads()->import($this->xlsx([self::line(['item' => 'Divan'])]), 'keep.xlsx', null, false);
        $gone = $this->uploads()->import($this->xlsx([self::line(['item' => 'Noutbuk']), self::line(['item' => 'Oturacaq'])]), 'gone.xlsx', null, false);

        $deleted = $this->uploads()->delete($gone['batch']);

        $this->assertSame(2, $deleted);
        $this->assertSame(1, EInvoice::count());
        $this->assertSame(2, ClassificationItem::where('batch', $gone['batch'])->count());  // history stays
        $batch = ImportBatch::where('key', $gone['batch'])->sole();                          // still labels the run
        $this->assertNotNull($batch->lines_deleted_at);
        $this->assertSame([$keep['batch']], $this->uploads()->recent()->pluck('key')->all());
    }

    public function test_delete_all_retires_every_upload(): void
    {
        $sablon = $this->uploads()->import($this->xlsx([self::line(['item' => 'Divan'])]), 's.xlsx', null, false);
        $legacyPath = storage_path('app/test-legacy-'.bin2hex(random_bytes(4)).'.csv');
        $this->paths[] = $legacyPath;
        $fh = fopen($legacyPath, 'w');
        fputcsv($fh, array_fill(0, 15, 'h'));
        fputcsv($fh, [1, 'A_1', 'T_1', '2026-01-01', '2026-01-02', 'MT', '1', 0, 100, 0, 0, 0, 18, 0, 118]);
        fclose($fh);
        $legacy = $this->uploads()->import($legacyPath, 'l.csv', null, false);

        $this->uploads()->deleteAll();

        $this->assertSame(0, EInvoice::count());
        $this->assertNotNull(ImportBatch::where('key', $sablon['batch'])->value('lines_deleted_at'));
        $this->assertFalse(ImportBatch::where('key', $legacy['batch'])->exists());   // nothing to keep it for
        $this->assertTrue($this->uploads()->recent()->isEmpty());
    }

    public function test_re_uploading_only_known_invoices_creates_no_empty_upload(): void
    {
        $path = $this->xlsx([self::line(['series' => 'MT', 'number' => '1', 'item' => 'Divan'])]);
        $this->uploads()->import($path, 'first.xlsx', null, false);

        $again = $this->uploads()->import($path, 'again.xlsx', null, true);

        $this->assertNull($again['error']);
        $this->assertSame(0, $again['imported']);
        $this->assertSame(1, $again['skipped']);
        $this->assertNull($again['batch']);
        $this->assertSame(1, ImportBatch::count());
    }

    public function test_deleting_the_run_in_review_keeps_the_invoice_upload(): void
    {
        $report = $this->uploads()->import($this->xlsx([self::line(['item' => 'Divan'])]), 'kept.xlsx', null, false);

        Livewire::actingAs(User::factory()->create())
            ->test(ReviewQueue::class, ['batch' => $report['batch']])
            ->call('deleteBatch');

        $this->assertSame(0, ClassificationItem::count());
        $this->assertNull(EInvoice::sole()->classification_item_id);        // the line stays, unlinked
        $this->assertSame(['kept.xlsx'], $this->uploads()->recent()->pluck('label')->all());
    }
}
