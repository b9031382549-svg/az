<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import production ProductBriefs (pulled as row_to_json) into the local
 * product_briefs cache, so a local mechanism run reuses the exact prod briefs
 * (cache hit by source_hash+prompt_version) instead of paying gpt-4o per item.
 */
class ClassifyImportBriefs extends Command
{
    protected $signature = 'classify:import-briefs
        {--file=research-data/finetune/contrastive/prod_briefs_full.jsonl : row_to_json lines from prod}';

    protected $description = 'Load prod ProductBriefs into local product_briefs cache (insert-or-ignore).';

    public function handle(): int
    {
        $path = base_path((string) $this->option('file'));
        if (! is_file($path)) {
            $this->error("Missing: {$path}");

            return self::FAILURE;
        }

        $cols = ['source_hash', 'prompt_version', 'identity', 'purpose', 'function_class',
            'material_value', 'material_basis', 'decisive_axis', 'confidence', 'ok', 'data', 'model'];

        $fh = fopen($path, 'r');
        $n = 0;
        $skipped = 0;
        $now = now();
        while (($line = fgets($fh)) !== false) {
            $r = json_decode($line, true);
            if (! is_array($r) || empty($r['source_hash'])) {
                continue;
            }
            $row = ['created_at' => $now, 'updated_at' => $now];
            foreach ($cols as $c) {
                $v = $r[$c] ?? null;
                // `data` came through as JSON already-decoded → re-encode for the json column.
                $row[$c] = $c === 'data' && $v !== null ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
            }
            $done = DB::table('product_briefs')->insertOrIgnore($row);
            $done ? $n++ : $skipped++;
        }
        fclose($fh);

        $this->info("Imported {$n} briefs (skipped/existing {$skipped}). Local total: ".DB::table('product_briefs')->count());

        return self::SUCCESS;
    }
}
