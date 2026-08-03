<?php

namespace Tests\Feature\Import;

use App\Services\Import\InvoiceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportEInvoicesCommandTest extends TestCase
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

    /** @return array<string, mixed> */
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

    public function test_fresh_flag_truncates_before_importing(): void
    {
        $existing = $this->csvPath();
        $this->writeCsv($existing, [$this->row(['series' => 'ZZ', 'number' => '9001'])]);
        app(InvoiceImporter::class)->import($existing, false);
        $this->assertDatabaseCount('e_invoices', 1);

        $path = $this->csvPath();
        $this->writeCsv($path, [
            $this->row(['series' => 'AA', 'number' => '1001']),
            $this->row(['series' => 'AA', 'number' => '1002']),
        ]);

        $this->artisan('data:import-invoices', ['path' => $path, '--fresh' => true])
            ->assertExitCode(0);

        // The pre-existing ZZ/9001 row is gone — --fresh truncated first, not just skipped duplicates.
        $this->assertDatabaseCount('e_invoices', 2);
        $this->assertDatabaseMissing('e_invoices', ['series' => 'ZZ', 'number' => '9001']);
    }

    public function test_without_fresh_flag_appends_on_top(): void
    {
        $existing = $this->csvPath();
        $this->writeCsv($existing, [$this->row(['series' => 'ZZ', 'number' => '9002'])]);
        app(InvoiceImporter::class)->import($existing, false);
        $this->assertDatabaseCount('e_invoices', 1);

        $path = $this->csvPath();
        $this->writeCsv($path, [$this->row(['series' => 'AA', 'number' => '1003'])]);

        $this->artisan('data:import-invoices', ['path' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseCount('e_invoices', 2);
    }
}
