<?php

namespace Tests\Feature\Import;

use App\Livewire\Invoices;
use App\Livewire\UploadInvoices;
use App\Models\ClassificationItem;
use App\Models\EInvoice;
use App\Models\ImportBatch;
use App\Models\RubricatorNode;
use App\Models\User;
use App\Services\Export\InvoiceLinesExporter;
use App\Services\Import\InvoiceLinesImporter;
use App\Services\Import\SheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceLinesExportTest extends TestCase
{
    use RefreshDatabase;

    /** Our columns, after the export's 24. */
    private const OURS = ['Our code', 'Category', 'Good or service', 'Status', 'Method', 'Reliability, %', 'Matches the declared code', 'Upload', 'Decision page'];

    private string $batch;

    /** @var string[] */
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('classify.search_resolver.enabled', true);
        $this->batch = (string) Str::uuid();
        ImportBatch::create(['key' => $this->batch, 'label' => 'Şablon_test_130.xlsx', 'source' => 'invoices', 'format' => 'sablon']);
        RubricatorNode::create(['code' => '0401', 'level' => 2, 'kind' => 'good', 'title' => 'Süd və qaymaq', 'title_en' => 'Milk and cream']);
        RubricatorNode::create(['code' => '99', 'level' => 1, 'kind' => 'service', 'title' => 'Xidmətlər', 'title_en' => 'Services']);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $results
     */
    private function item(array $attrs, array $results = []): ClassificationItem
    {
        $item = ClassificationItem::create($attrs + [
            'batch' => $this->batch, 'source_text' => 'item '.bin2hex(random_bytes(4)), 'source_hash' => bin2hex(random_bytes(32)),
        ]);
        foreach ($results as $r) {
            $item->results()->create($r + ['status' => 'needs_review']);
        }

        return $item;
    }

    /** @param array<string, mixed> $fields */
    private function line(?ClassificationItem $item, array $fields = []): EInvoice
    {
        return EInvoice::create($fields + [
            'import_batch' => $this->batch, 'classification_item_id' => $item?->id,
            'item_name' => $item?->source_text, 'total_amount' => 10,
        ]);
    }

    /**
     * Export the upload (or $part of it) and read the file back.
     *
     * @return array{0: array<int, mixed>, 1: array<int, array<string, mixed>>} header, rows keyed by header
     */
    private function export(int $part = 1, string $status = ''): array
    {
        $path = tempnam(sys_get_temp_dir(), 'lines').'.xlsx';
        $this->paths[] = $path;
        app(InvoiceLinesExporter::class)->write(EInvoice::query()->filtered('', $this->batch, $status), $path, $part);

        return $this->read($path);
    }

    /** @return array{0: array<int, mixed>, 1: array<int, array<string, mixed>>} */
    private function read(string $path): array
    {
        [$header, $rows] = app(SheetReader::class)->read($path);

        return [$header, array_map(fn (array $row) => array_combine($header, $row), $rows)];
    }

    public function test_every_line_comes_back_with_the_export_columns_and_our_answer(): void
    {
        $milk = $this->item(['resolution' => 'agreed', 'final_code' => '0401', 'kind' => 'good'], [
            ['mechanism' => 'cache', 'matched_code' => '0401', 'status' => 'auto_confirmed', 'trace' => ['source' => 'gold']],
        ]);
        $guard = $this->item(['resolution' => 'agreed', 'final_code' => '99', 'kind' => 'service'], [
            ['mechanism' => 'direct', 'matched_code' => '99', 'kind' => 'service', 'status' => 'auto_confirmed'],
            ['mechanism' => 'sorter', 'kind' => 'service', 'confidence' => 0.97],
        ]);
        $trash = $this->item(['resolution' => 'trash'], [['mechanism' => 'trash', 'status' => 'trash', 'trace' => ['rule' => 'digits']]]);
        $searching = $this->item(['resolution' => 'conflict']);                 // no web-search row yet
        $open = $this->item(['resolution' => 'no_match']);

        $this->line($milk, [
            'supplier_tin' => '8273197781', 'recipient_tin' => '1808172501', 'series' => 'MT2609', 'number' => '7658433',
            'invoice_key' => 'MT2609|7658433', 'invoice_date' => '2026-09-27', 'declared_code' => '0401201100',
            'unit' => 'ədəd', 'quantity' => 68, 'total_amount' => 176.53,
        ]);
        $this->line($milk, ['declared_code' => '2202100000']);                   // the supplier declared a soft drink
        $this->line($guard, ['declared_code' => '9996091200']);                  // a service against our "99"
        $this->line($trash);
        $this->line($searching);
        $this->line($open);
        $this->line(null, ['supplier_tin' => 'A_1', 'total_amount' => 118]);    // a legacy invoice-list row

        [$header, $rows] = $this->export();

        $this->assertSame([...array_values(InvoiceLinesImporter::headers()), ...self::OURS], $header);
        $this->assertCount(7, $rows);

        [$first, $mismatch, $service, $setAside, $inFlight, $waiting, $legacy] = $rows;
        // The export's own columns, as the export writes them.
        $this->assertSame('8273197781', $first['e-QF təqdim edənin VÖEN']);
        $this->assertSame('0401201100', $first['Kodu']);                        // text: the leading zero survives
        $this->assertSame('27.09.2026', $first['e-Qaimənin tarixi']);
        $this->assertSame('MT2609', $first['e-Qaimənin seriyası']);
        $this->assertEquals(176.53, $first['Yekun məbləğ']);
        $this->assertEquals(68, $first['Malın miqdarı']);
        // Ours.
        $this->assertSame('0401', $first['Our code']);
        $this->assertSame('Milk and cream', $first['Category']);
        $this->assertSame('good', $first['Good or service']);
        $this->assertSame('Classified', $first['Status']);
        $this->assertSame('Memory', $first['Method']);
        $this->assertEquals(97, $first['Reliability, %']);
        $this->assertSame('yes', $first['Matches the declared code']);
        $this->assertSame('Şablon_test_130.xlsx', $first['Upload']);
        $this->assertSame(route('review.decision', ['item' => $milk->id]), $first['Decision page']);

        $this->assertSame('no', $mismatch['Matches the declared code']);

        $this->assertSame('99', $service['Our code']);
        $this->assertSame('Services', $service['Category']);
        $this->assertSame('service', $service['Good or service']);
        $this->assertSame('AI: Direct + sorter', $service['Method']);
        $this->assertSame('yes', $service['Matches the declared code']);       // 9996… is a service, as ours

        $this->assertNull($setAside['Our code']);
        $this->assertSame('Not a product', $setAside['Status']);
        $this->assertSame('not a product', $setAside['Good or service']);
        $this->assertEquals(98, $setAside['Reliability, %']);

        $this->assertSame('In progress', $inFlight['Status']);
        $this->assertNull($inFlight['Reliability, %']);
        $this->assertSame('Needs review', $waiting['Status']);
        $this->assertNull($waiting['Our code']);

        $this->assertSame('A_1', $legacy['e-QF təqdim edənin VÖEN']);
        $this->assertNull($legacy['Status']);
        $this->assertSame('Şablon_test_130.xlsx', $legacy['Upload']);
    }

    public function test_a_big_selection_is_cut_into_parts_in_line_order(): void
    {
        config()->set('uploads.export_part_lines', 2);
        $item = $this->item(['resolution' => 'agreed', 'final_code' => '0401', 'kind' => 'good']);
        foreach (['L1', 'L2', 'L3', 'L4', 'L5'] as $name) {
            $this->line($item, ['item_name' => $name]);
        }

        $this->assertSame(3, InvoiceLinesExporter::parts(5));
        $this->assertSame(['L1', 'L2'], array_column($this->export(1)[1], 'Malın adı'));
        $this->assertSame(['L3', 'L4'], array_column($this->export(2)[1], 'Malın adı'));
        $this->assertSame(['L5'], array_column($this->export(3)[1], 'Malın adı'));
    }

    public function test_the_download_follows_the_filters_and_is_audited(): void
    {
        $done = $this->item(['resolution' => 'agreed', 'final_code' => '0401', 'kind' => 'good']);
        $open = $this->item(['resolution' => 'no_match']);
        $this->line($done, ['item_name' => 'Süd']);
        $this->line($open, ['item_name' => 'Nəsə']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('invoices.export', ['upload' => $this->batch, 'status' => 'classified']));

        $response->assertOk()->assertDownload();
        $this->assertStringContainsString('sablon_test_130_', (string) $response->headers->get('Content-Disposition'));
        $path = tempnam(sys_get_temp_dir(), 'dl').'.xlsx';
        $this->paths[] = $path;
        file_put_contents($path, $response->streamedContent());
        $this->assertSame(['Süd'], array_column($this->read($path)[1], 'Malın adı'));
        $this->assertDatabaseHas('activity_log', ['action' => 'invoice.export']);
    }

    public function test_the_invoices_page_filters_by_status_and_offers_the_download_or_its_parts(): void
    {
        $done = $this->item(['resolution' => 'agreed', 'final_code' => '0401', 'kind' => 'good']);
        $set = $this->item(['resolution' => 'trash']);
        $this->line($done, ['item_name' => 'Süd Atena']);
        $this->line($set, ['item_name' => 'Müqavilə 12']);

        $page = Livewire::actingAs(User::factory()->create())->test(Invoices::class)
            ->set('status', 'trash')
            ->assertSee('Müqavilə 12')
            ->assertDontSee('Süd Atena')
            ->assertSee(route('invoices.export', ['status' => 'trash']));

        config()->set('uploads.export_part_lines', 1);
        $page->set('status', '')
            ->assertSee(route('invoices.export', ['part' => 2]))
            ->assertSee('Part 2');
    }

    public function test_an_upload_links_to_its_lines_download(): void
    {
        $this->line($this->item(['resolution' => 'agreed', 'final_code' => '0401', 'kind' => 'good']));

        Livewire::actingAs(User::factory()->create())->test(UploadInvoices::class)
            ->assertSee(route('invoices.export', ['upload' => $this->batch]));
    }
}
