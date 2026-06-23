<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportCatalog extends Command
{
    protected $signature = 'data:import-catalog
        {path=start-data/task 2/eqm_mal_kodlari-v1.xls : Path to the XİF MN registry (.xls)}
        {--fresh : Truncate catalog before importing}';

    protected $description = 'Import the XİF MN goods & services code registry into the catalog table';

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');

        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            DB::statement('TRUNCATE catalog RESTART IDENTITY CASCADE');
            $this->info('Truncated catalog.');
        }

        $this->info("Loading {$path} (this can take a moment) ...");
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);

        $header = array_shift($rows); // CODE, ADI, VAHID, STATE
        $now = Carbon::now();
        $buffer = [];
        $count = 0;
        $bar = $this->output->createProgressBar(count($rows));

        foreach ($rows as $row) {
            $code = $this->normalizeCode($row[0] ?? null);
            $name = trim((string) ($row[1] ?? ''));
            if ($code === '' || $name === '') {
                $bar->advance();
                continue;
            }
            $unit = trim((string) ($row[2] ?? ''));

            $buffer[] = [
                'code' => $code,
                'name' => $name,
                'unit' => ($unit === '' || $unit === '–') ? null : $unit,
                'kind' => str_starts_with($code, '99') ? 'service' : 'good',
                'chapter' => substr($code, 0, 2),
                'position' => substr($code, 0, 4),
                'subposition' => substr($code, 0, 6),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $count++;

            if (count($buffer) >= 1000) {
                $this->flush($buffer);
                $buffer = [];
            }
            $bar->advance();
        }
        if ($buffer) {
            $this->flush($buffer);
        }
        $bar->finish();
        $this->newLine(2);

        $goods = DB::table('catalog')->where('kind', 'good')->count();
        $services = DB::table('catalog')->where('kind', 'service')->count();
        $this->info("Imported {$count} codes. Goods: {$goods}, Services: {$services}.");

        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $buffer */
    private function flush(array $buffer): void
    {
        // The registry contains a few duplicate codes; Postgres forbids a single
        // ON CONFLICT statement from touching the same target row twice, so keep
        // one record per code within the batch (last wins).
        $deduped = collect($buffer)->keyBy('code')->values()->all();

        DB::table('catalog')->upsert(
            $deduped,
            ['code'],
            ['name', 'unit', 'kind', 'chapter', 'position', 'subposition', 'is_active', 'updated_at'],
        );
    }

    private function normalizeCode(mixed $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        if ($digits === '') {
            return '';
        }

        // Preserve leading zeros (HS chapters 01–09): pad short codes to 10.
        return strlen($digits) < 10 ? str_pad($digits, 10, '0', STR_PAD_LEFT) : $digits;
    }
}
