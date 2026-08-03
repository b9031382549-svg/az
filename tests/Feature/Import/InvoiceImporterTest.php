<?php

namespace Tests\Feature\Import;

use App\Services\Import\InvoiceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceImporterTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function csvPath(): string
    {
        $path = storage_path('app/test-invoices-'.bin2hex(random_bytes(4)).'.csv');
        $this->paths[] = $path;

        return $path;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function writeCsv(string $path, array $rows): void
    {
        $fh = fopen($path, 'w');
        fputcsv($fh, InvoiceImporter::COLUMNS);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(fn (string $col) => $row[$col], InvoiceImporter::COLUMNS));
        }
        fclose($fh);
    }

    /**
     * Builds one full row in COLUMNS order, with sane defaults for the columns the
     * DB requires (supplier_tin/recipient_tin/invoice_date are NOT NULL), overridable
     * per test for the fields that matter (series, number, row_no, ...).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        $defaults = [
            'row_no' => 1,
            'supplier_tin' => '1000000001',
            'recipient_tin' => '2000000002',
            'invoice_date' => '2026-01-01',
            'approval_date' => '2026-01-02',
            'series' => 'AA',
            'number' => '1001',
            'excise_amount' => '0',
            'vat_taxable_amount' => '100',
            'non_vat_taxable_amount' => '0',
            'vat_exempt_amount' => '0',
            'zero_rated_vat_amount' => '0',
            'vat_amount' => '18',
            'road_tax' => '0',
            'total_amount' => '118',
        ];

        return array_merge($defaults, $overrides);
    }

    public function test_importing_twice_without_skip_duplicates_duplicates_every_row(): void
    {
        $path = $this->csvPath();
        $this->writeCsv($path, [
            $this->row(['series' => 'AA', 'number' => '1001']),
            $this->row(['series' => 'AA', 'number' => '1002']),
            $this->row(['series' => 'AA', 'number' => '1003']),
        ]);

        $importer = app(InvoiceImporter::class);

        $first = $importer->import($path, false);
        $this->assertSame(3, $first['imported']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame(3, $first['total']);

        $second = $importer->import($path, false);
        $this->assertSame(3, $second['imported']);
        $this->assertSame(0, $second['skipped']);
        $this->assertSame(6, $second['total']);

        $this->assertDatabaseCount('e_invoices', 6);
    }

    public function test_importing_same_file_twice_with_skip_duplicates_skips_the_second_time(): void
    {
        $path = $this->csvPath();
        $this->writeCsv($path, [
            $this->row(['series' => 'BB', 'number' => '2001']),
            $this->row(['series' => 'BB', 'number' => '2002']),
        ]);

        $importer = app(InvoiceImporter::class);

        $first = $importer->import($path, true);
        $this->assertSame(2, $first['imported']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame(2, $first['total']);

        $second = $importer->import($path, true);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(2, $second['skipped']);
        $this->assertSame(2, $second['total']);

        $this->assertDatabaseCount('e_invoices', 2);
    }

    public function test_partial_overlap_only_imports_the_new_rows(): void
    {
        $importer = app(InvoiceImporter::class);

        $pathA = $this->csvPath();
        $this->writeCsv($pathA, [
            $this->row(['series' => 'CC', 'number' => '3001']),
            $this->row(['series' => 'CC', 'number' => '3002']),
        ]);
        $importer->import($pathA, true);

        $pathB = $this->csvPath();
        $this->writeCsv($pathB, [
            $this->row(['series' => 'CC', 'number' => '3002']), // already in DB
            $this->row(['series' => 'CC', 'number' => '3003']), // new
        ]);

        $result = $importer->import($pathB, true);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(3, $result['total']);

        $this->assertDatabaseCount('e_invoices', 3);
    }

    public function test_duplicate_within_the_same_file_only_imports_the_first_occurrence(): void
    {
        $path = $this->csvPath();
        $this->writeCsv($path, [
            $this->row(['series' => 'DD', 'number' => '4001', 'row_no' => 1]),
            $this->row(['series' => 'DD', 'number' => '4001', 'row_no' => 2]),
        ]);

        $importer = app(InvoiceImporter::class);
        $result = $importer->import($path, true);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['total']);

        $this->assertDatabaseCount('e_invoices', 1);
        $this->assertSame(1, (int) DB::table('e_invoices')->value('row_no'));
    }

    public function test_delete_all_truncates_and_returns_the_prior_count(): void
    {
        $path = $this->csvPath();
        $this->writeCsv($path, [
            $this->row(['series' => 'EE', 'number' => '5001']),
            $this->row(['series' => 'EE', 'number' => '5002']),
            $this->row(['series' => 'EE', 'number' => '5003']),
        ]);

        $importer = app(InvoiceImporter::class);
        $importer->import($path, false);

        $this->assertDatabaseCount('e_invoices', 3);

        $deleted = $importer->deleteAll();

        $this->assertSame(3, $deleted);
        $this->assertDatabaseCount('e_invoices', 0);
    }

    public function test_preview_flags_duplicates_within_the_same_file_matching_import_behavior(): void
    {
        $path = $this->csvPath();
        $this->writeCsv($path, [
            $this->row(['series' => 'FF', 'number' => '6001', 'row_no' => 1]),
            $this->row(['series' => 'FF', 'number' => '6001', 'row_no' => 2]),
            $this->row(['series' => 'FF', 'number' => '6002', 'row_no' => 3]),
        ]);

        $importer = app(InvoiceImporter::class);

        $preview = $importer->preview($path);
        $this->assertSame(3, $preview['count']);
        $this->assertSame(1, $preview['duplicates']);

        $result = $importer->import($path, true);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_blank_series_or_number_rows_are_never_deduplicated(): void
    {
        $path = $this->csvPath();
        $this->writeCsv($path, [
            $this->row(['series' => '', 'number' => '', 'row_no' => 1]),
            $this->row(['series' => '', 'number' => '', 'row_no' => 2]),
        ]);

        $importer = app(InvoiceImporter::class);
        $result = $importer->import($path, true);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(2, $result['total']);

        $this->assertDatabaseCount('e_invoices', 2);
    }
}
