<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ImportEInvoices extends Command
{
    protected $signature = 'data:import-invoices
        {path=start-data/task 1/FoodWholesale_sampleData.xlsx : Path to the .xlsx file}
        {--fresh : Truncate e_invoices before importing}';

    protected $description = 'Import e-invoice sample data from an .xlsx file into e_invoices';

    /** Column order as it appears in the sample file. */
    private const COLUMNS = [
        'row_no', 'supplier_tin', 'recipient_tin', 'invoice_date', 'approval_date',
        'series', 'number', 'excise_amount', 'vat_taxable_amount', 'non_vat_taxable_amount',
        'vat_exempt_amount', 'zero_rated_vat_amount', 'vat_amount', 'road_tax', 'total_amount',
    ];

    private const DATE_COLS = ['invoice_date', 'approval_date'];
    private const DECIMAL_COLS = [
        'excise_amount', 'vat_taxable_amount', 'non_vat_taxable_amount', 'vat_exempt_amount',
        'zero_rated_vat_amount', 'vat_amount', 'road_tax', 'total_amount',
    ];

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            DB::table('e_invoices')->truncate();
            $this->info('Truncated e_invoices.');
        }

        $this->info("Loading {$path} ...");
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getActiveSheet();
        // formatData=false → date cells come through as Excel serial numbers we convert ourselves.
        $rows = $sheet->toArray(null, true, false, false);

        $header = array_shift($rows);
        if (count($header) < count(self::COLUMNS)) {
            $this->error('Unexpected header: got '.count($header).' columns, expected '.count(self::COLUMNS));
            return self::FAILURE;
        }

        $now = Carbon::now();
        $buffer = [];
        $imported = 0;
        $bar = $this->output->createProgressBar(count($rows));

        foreach ($rows as $row) {
            if ($this->isBlank($row)) {
                continue;
            }
            $record = ['created_at' => $now, 'updated_at' => $now];
            foreach (self::COLUMNS as $i => $col) {
                $record[$col] = $this->normalize($col, $row[$i] ?? null);
            }
            $buffer[] = $record;
            $imported++;

            if (count($buffer) >= 1000) {
                DB::table('e_invoices')->insert($buffer);
                $buffer = [];
            }
            $bar->advance();
        }
        if ($buffer) {
            DB::table('e_invoices')->insert($buffer);
        }
        $bar->finish();
        $this->newLine(2);
        $this->info("Imported {$imported} invoices. Total now: ".DB::table('e_invoices')->count());

        return self::SUCCESS;
    }

    private function isBlank(array $row): bool
    {
        foreach ($row as $v) {
            if ($v !== null && $v !== '') {
                return false;
            }
        }
        return true;
    }

    private function normalize(string $col, mixed $value): mixed
    {
        if (in_array($col, self::DATE_COLS, true)) {
            if ($value === null || $value === '') {
                return null;
            }
            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }
            $ts = strtotime((string) $value);
            return $ts ? date('Y-m-d', $ts) : null;
        }

        if (in_array($col, self::DECIMAL_COLS, true)) {
            return is_numeric($value) ? round((float) $value, 2) : 0;
        }

        if ($col === 'row_no') {
            return is_numeric($value) ? (int) $value : null;
        }

        return $value === null ? null : trim((string) $value);
    }
}
