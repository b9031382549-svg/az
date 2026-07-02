<?php

namespace App\Console\Commands;

use App\Services\Import\InvoiceImporter;
use Illuminate\Console\Command;

class ImportEInvoices extends Command
{
    protected $signature = 'data:import-invoices
        {path=start-data/task 1/FoodWholesale_sampleData.xlsx : Path to the .xlsx file}
        {--fresh : Truncate e_invoices before importing}';

    protected $description = 'Import e-invoice sample data from an .xlsx file into e_invoices';

    public function handle(InvoiceImporter $importer): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $this->info("Importing {$path} ...");
        $result = $importer->import($path, (bool) $this->option('fresh'));

        if ($result['error']) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->info("Imported {$result['imported']} invoices. Total now: {$result['total']}.");

        return self::SUCCESS;
    }
}
