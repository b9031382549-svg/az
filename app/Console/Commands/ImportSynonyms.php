<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportSynonyms extends Command
{
    protected $signature = 'catalog:import-synonyms {path : JSONL file, one {"code": "...", "synonyms": "..."} per line}';

    protected $description = 'Load everyday synonyms into catalog.synonyms from a JSONL file (idempotent, matched by code)';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $updated = 0;
        $skipped = 0;
        $line = 0;

        DB::beginTransaction();
        try {
            while (($raw = fgets($handle)) !== false) {
                $line++;
                $raw = trim($raw);
                if ($raw === '') {
                    continue;
                }

                $row = json_decode($raw, true);
                $code = isset($row['code']) ? trim((string) $row['code']) : '';
                $synonyms = isset($row['synonyms']) ? trim((string) $row['synonyms']) : '';

                if ($code === '' || $synonyms === '') {
                    $skipped++;
                    continue;
                }

                $updated += DB::table('catalog')->where('code', $code)->update(['synonyms' => $synonyms]);

                if ($updated % 1000 === 0 && $updated > 0) {
                    $this->line("  updated {$updated} ...");
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);
            $this->error("Failed at line {$line}: ".$e->getMessage());
            return self::FAILURE;
        }
        fclose($handle);

        $total = DB::table('catalog')->whereNotNull('synonyms')->count();
        $this->info("Done. Rows updated: {$updated}, skipped: {$skipped}. Catalog rows with synonyms: {$total}.");

        return self::SUCCESS;
    }
}
