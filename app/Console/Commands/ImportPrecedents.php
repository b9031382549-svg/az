<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ImportPrecedents extends Command
{
    protected $signature = 'data:import-precedents
        {path=research-data/precedents_az.jsonl : Path to the precedents JSONL (id,hs6,code,lang,desc,az)}
        {--fresh : Truncate precedents before importing}';

    protected $description = 'Load the customs precedent corpus (AZ name → HS) into the precedents table';

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');

        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            DB::statement('TRUNCATE precedents RESTART IDENTITY CASCADE');
            $this->info('Truncated precedents.');
        }

        $now = Carbon::now();
        $buffer = [];
        $count = 0;
        $skipped = 0;

        $fh = fopen($path, 'r');
        $total = 0;
        while (fgets($fh) !== false) {
            $total++;
        }
        rewind($fh);
        $bar = $this->output->createProgressBar($total);

        while (($line = fgets($fh)) !== false) {
            $bar->advance();
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (! is_array($row) || ! isset($row['id'], $row['hs6'], $row['az']) || trim((string) $row['az']) === '') {
                $skipped++;

                continue;
            }
            $lang = isset($row['lang']) ? (string) $row['lang'] : null;

            $buffer[] = [
                'id' => (int) $row['id'],
                // FCS rows are the Russian ones; everything else is EBTI (EU).
                'source' => ($lang === 'ru') ? 'fcs' : 'ebti',
                'hs6' => substr(preg_replace('/\D/', '', (string) $row['hs6']) ?? '', 0, 6),
                'code' => (string) ($row['code'] ?? ''),
                'lang' => $lang,
                'desc' => (string) ($row['desc'] ?? ''),
                'az' => trim((string) $row['az']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $count++;

            if (count($buffer) >= 1000) {
                $this->flush($buffer);
                $buffer = [];
            }
        }
        if ($buffer) {
            $this->flush($buffer);
        }
        fclose($fh);
        $bar->finish();
        $this->newLine(2);

        $rows = DB::table('precedents')->count();
        $hs6 = DB::table('precedents')->distinct()->count('hs6');
        $this->info("Loaded {$count} precedents (skipped {$skipped}). Table: {$rows} rows over {$hs6} distinct HS6.");

        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $buffer */
    private function flush(array $buffer): void
    {
        // The dataset id is unique; keep one record per id within the batch (last wins)
        // so a single ON CONFLICT never touches the same target row twice.
        $deduped = collect($buffer)->keyBy('id')->values()->all();

        DB::table('precedents')->upsert(
            $deduped,
            ['id'],
            ['source', 'hs6', 'code', 'lang', 'desc', 'az', 'updated_at'],
        );
    }
}
