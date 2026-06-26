<?php

namespace App\Console\Commands;

use App\Services\Llm\OpenRouterClient;
use Illuminate\Console\Command;

class GenerateSynonyms extends Command
{
    protected $signature = 'catalog:generate-synonyms
                            {--batch=200 : codes per LLM call}
                            {--model= : override OPENROUTER_MODEL}';

    protected $description = 'Generate everyday Azerbaijani synonyms for catalog codes → catalog_synonyms.jsonl';

    public function handle(): int
    {
        $codesPath    = storage_path('app/catalog_codes.jsonl');
        $synonymsPath = storage_path('app/catalog_synonyms.jsonl');
        $batchSize    = max(1, (int) $this->option('batch'));
        $modelOverride = (string) ($this->option('model') ?? '');

        // 1. Load all source codes
        $allCodes = [];
        foreach (file($codesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
            $row = json_decode($raw, true);
            if ($row && isset($row['code'])) {
                $allCodes[$row['code']] = $row;
            }
        }

        // 2. Load already-processed codes
        $done = [];
        if (is_file($synonymsPath)) {
            foreach (file($synonymsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
                $row = json_decode($raw, true);
                if ($row && isset($row['code'])) {
                    $done[$row['code']] = true;
                }
            }
        }

        $pending = array_values(array_filter($allCodes, fn($r) => ! isset($done[$r['code']])));
        $total   = count($pending);

        $this->info(sprintf('%d total codes, %d done, %d pending', count($allCodes), count($done), $total));

        if ($total === 0) {
            $this->info('All codes already processed. Run catalog:import-synonyms to load into DB.');
            return self::SUCCESS;
        }

        $llm        = OpenRouterClient::fromConfig();
        $extraOpts  = $modelOverride !== '' ? ['model' => $modelOverride] : [];
        $batches    = array_chunk($pending, $batchSize);
        $numBatches = count($batches);
        $totalTok   = 0;
        $totalWritten = 0;

        $out = fopen($synonymsPath, 'a');
        if ($out === false) {
            $this->error("Cannot open {$synonymsPath} for writing.");
            return self::FAILURE;
        }

        foreach ($batches as $i => $batch) {
            $batchNum = $i + 1;
            $this->line(sprintf('[%d/%d] %d codes ...', $batchNum, $numBatches, count($batch)));

            try {
                $result = $llm->jsonWithUsage(
                    [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user',   'content' => $this->userPrompt($batch)],
                    ],
                    $extraOpts,
                );

                $tok = $result['usage']['total_tokens'] ?? 0;
                $totalTok += $tok;

                $written = 0;
                foreach ((array) ($result['data']['results'] ?? []) as $item) {
                    $code     = trim((string) ($item['code']     ?? ''));
                    $synonyms = trim((string) ($item['synonyms'] ?? ''));
                    if ($code === '' || $synonyms === '') {
                        continue;
                    }
                    fwrite($out, json_encode(['code' => $code, 'synonyms' => $synonyms], JSON_UNESCAPED_UNICODE) . "\n");
                    $written++;
                }
                $totalWritten += $written;
                $this->line(sprintf('  -> wrote %d/%d  tokens this batch: %d  total: %d',
                    $written, count($batch), $tok, $totalTok));
            } catch (\Throwable $e) {
                $this->error('  Batch failed: ' . $e->getMessage());
                // continue — skipped codes will be picked up on next run
            }
        }

        fclose($out);

        $this->info(sprintf(
            'Done. Written %d synonym rows. Total tokens: %d.',
            $totalWritten,
            $totalTok,
        ));
        $this->info("Next step: docker compose run --rm app php artisan catalog:import-synonyms storage/app/catalog_synonyms.jsonl");

        return self::SUCCESS;
    }

    private function systemPrompt(): string
    {
        return <<<'SYSTEM'
Sən Azərbaycan dilinin ekspertisən. Sənə XİF MN (HS) mallar/xidmətlər üzrə kodlar veriləcək — hər birinin formal HS adı ilə.
Hər kod üçün 3–8 qısa, danışıq dilindəki Azərbaycan termini yaz — qaimə-fakturada belə mövqeni necə qeyd edərlər.

Qaydalar:
- Yalnız latın əlifbasında Azərbaycan sözləri (ə, ü, ö, ğ, ş, ç, ı istifadə et)
- Gündəlik/danışıq adlar, sinonimlər, çox işlənən yazılış formaları
- Brendlər, ölçülər, artikullar, ölçü vahidləri yoxdur
- Formal HS adını dublikat etmə — məhz sinonimlər/danışıq variantları
- kind=service üçün xidmətin danışıq adını ver

Nümunə: kod 6302… (havlu/dəsmal) → "dəsmal, əl dəsmalı, mətbəx dəsmalı, hamam dəsmalı, məhrəba"
Nümunə: kod 9403… (mebel) → "mebel, şkaf, stol, kreslo, çarpayı, raf"

Cavabı JSON formatında ver:
{"results": [{"code": "...", "synonyms": "söz1, söz2, söz3"}, ...]}

Bütün kodlar üçün nəticə ver — heç birini buraxma.
SYSTEM;
    }

    private function userPrompt(array $batch): string
    {
        $lines = [];
        foreach ($batch as $r) {
            $name = str_replace(['"', "\n"], ["'", ' '], (string) $r['name']);
            $lines[] = sprintf('{"code":"%s","name":"%s","kind":"%s"}', $r['code'], $name, $r['kind']);
        }
        return "Aşağıdakı kodlar üçün sinonimlər ver:\n" . implode("\n", $lines);
    }
}
