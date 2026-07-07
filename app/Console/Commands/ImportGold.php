<?php

namespace App\Console\Commands;

use App\Models\GoldLabel;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import the two external reference ("gold") files into gold_labels:
 *   - Ivan  — one AI's full 10-digit codes (sheet result_goods).
 *   - Fedor — the "Validated Gold (both agree)" sheet: 4-digit heading +
 *     good/service, where two models (Claude + GPT) agreed.
 * Idempotent (upsert by source+name_key). No LLM calls — pure file → table.
 */
class ImportGold extends Command
{
    protected $signature = 'benchmark:import-gold
        {--ivan= : path to Ivan xlsx (default start-data/gold/ivan.xlsx)}
        {--fedor= : path to Fedor xlsx (default start-data/gold/fedor.xlsx)}
        {--fresh : truncate gold_labels first}';

    protected $description = 'Import Ivan/Fedor reference labels into gold_labels';

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');

        if ($this->option('fresh')) {
            GoldLabel::truncate();
            $this->warn('Truncated gold_labels.');
        }

        $ivan = $this->option('ivan') ?: base_path('start-data/gold/ivan.xlsx');
        $fedor = $this->option('fedor') ?: base_path('start-data/gold/fedor.xlsx');

        $n = 0;
        if (is_file($ivan)) {
            $n += $this->importIvan($ivan);
        } else {
            $this->warn("Ivan file not found: {$ivan}");
        }
        if (is_file($fedor)) {
            $n += $this->importFedor($fedor);
        } else {
            $this->warn("Fedor file not found: {$fedor}");
        }

        $this->info("Done. gold_labels now holds {$n} rows across ".GoldLabel::distinct('source')->count('source').' source(s).');
        foreach (GoldLabel::selectRaw('source, count(*) c')->groupBy('source')->pluck('c', 'source') as $s => $c) {
            $this->line("  {$s}: {$c}");
        }

        return self::SUCCESS;
    }

    /** Ivan: name → full 10-digit code (sheet result_goods, Azerbaijani names). */
    private function importIvan(string $path): int
    {
        [$rows, $col] = $this->sheet($path, 'result_goods');
        $name = $this->find($col, ['наименование', 'məhsulun']);
        $code = $this->find($col, ['код каталога']);
        $unit = $this->find($col, ['единица']);
        $cat = $this->find($col, ['категория']);
        $conf = $this->find($col, ['уверенность']);
        $desc = $this->find($col, ['описание кода']);

        $out = [];
        foreach ($rows as $r) {
            $raw = trim((string) ($r[$name] ?? ''));
            if ($raw === '') {
                continue;
            }
            $full = $this->normalizeCode($r[$code] ?? null);
            $out[] = [
                'source' => 'ivan',
                'tier' => 'single',
                'name' => $raw,
                'name_key' => GoldLabel::keyFor($raw),
                'code' => $full,
                'heading' => $full ? mb_substr($full, 0, 4) : null,
                'chapter' => $full ? mb_substr($full, 0, 2) : null,
                'is_service' => false, // result_goods is a goods sheet
                'confidence' => null,  // Ivan's confidence is textual (высокая/…)
                'unit' => $this->str($r[$unit] ?? null),
                'category' => $this->str($r[$cat] ?? null),
                'meta' => array_filter([
                    'raw_code' => $this->str($r[$code] ?? null),
                    'confidence_text' => $this->str($r[$conf] ?? null),
                    'code_desc' => $this->str($r[$desc] ?? null),
                ]),
            ];
        }

        return $this->store($out, 'ivan');
    }

    /**
     * Fedor: the FULL Claude_Opus (1200) sheet is the reference — 4-digit heading +
     * good/service, no full code. Rows where a second model (GPT) also agreed at the
     * heading (the "Validated Gold" sheet) are marked tier=validated; the rest are
     * tier=claude (single model / the two disagreed). GPT's own pick is carried in
     * meta for display. Reference only — never fed to the classifier.
     */
    private function importFedor(string $path): int
    {
        // Name-keys that two models validated.
        [$vrows, $vcol] = $this->sheet($path, 'Validated Gold (both agree)');
        $vName = $this->find($vcol, ['name']);
        $validated = [];
        foreach ($vrows as $r) {
            $k = GoldLabel::keyFor(trim((string) ($r[$vName] ?? '')));
            if ($k !== '') {
                $validated[$k] = true;
            }
        }

        // GPT's cross-check pick + agreement, keyed by name.
        [$ccrows, $cccol] = $this->sheet($path, 'CrossCheck Claude×GPT');
        [$ccName, $ccGpt, $ccAgree, $ccStatus] = [$this->find($cccol, ['name']), $this->find($cccol, ['gpt_heading']), $this->find($cccol, ['agree_4digit']), $this->find($cccol, ['status'])];
        $cross = [];
        foreach ($ccrows as $r) {
            $k = GoldLabel::keyFor(trim((string) ($r[$ccName] ?? '')));
            if ($k !== '') {
                $cross[$k] = ['gpt_heading' => $this->str($r[$ccGpt] ?? null), 'agree_4digit' => $this->str($r[$ccAgree] ?? null), 'crosscheck' => $this->str($r[$ccStatus] ?? null)];
            }
        }

        [$rows, $col] = $this->sheet($path, 'Claude_Opus (1200)');
        $name = $this->find($col, ['name']);
        $svc = $this->find($col, ['service']);
        $chap = $this->find($col, ['chapter']);
        $head = $this->find($col, ['heading']);
        $group = $this->find($col, ['group']);
        $conf = $this->find($col, ['confidence']);
        $note = $this->find($col, ['note']);
        $usedWeb = $this->find($col, ['used_web']);

        $out = [];
        foreach ($rows as $r) {
            $raw = trim((string) ($r[$name] ?? ''));
            if ($raw === '') {
                continue;
            }
            $key = GoldLabel::keyFor($raw);
            $isService = $this->bool($r[$svc] ?? null);
            $heading = $this->str($r[$head] ?? null);
            $heading = $heading ? mb_substr(preg_replace('/\D/', '', $heading), 0, 4) : null;
            $out[] = [
                'source' => 'fedor',
                'tier' => isset($validated[$key]) ? 'validated' : 'claude',
                'name' => $raw,
                'name_key' => $key,
                'code' => null, // Fedor is heading-level only
                'heading' => $isService ? null : ($heading ?: null),
                'chapter' => $this->str($r[$chap] ?? null) ?: ($heading ? mb_substr($heading, 0, 2) : null),
                'is_service' => $isService,
                'confidence' => is_numeric($r[$conf] ?? null) ? (float) $r[$conf] : null,
                'unit' => null,
                'category' => $this->str($r[$group] ?? null),
                'meta' => array_filter([
                    'note' => $this->str($r[$note] ?? null),
                    'used_web' => $this->str($r[$usedWeb] ?? null),
                ] + ($cross[$key] ?? [])),
            ];
        }

        return $this->store($out, 'fedor');
    }

    /**
     * Dedup by (source, name_key) — a single upsert must not target the same key
     * twice — then upsert in chunks. Returns the row count for this source.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function store(array $rows, string $source): int
    {
        $deduped = collect($rows)->keyBy('name_key')->values()
            ->map(fn ($r) => ['meta' => json_encode($r['meta']), 'created_at' => now(), 'updated_at' => now()] + $r)
            ->all();

        foreach (array_chunk($deduped, 500) as $chunk) {
            GoldLabel::upsert(
                $chunk,
                ['source', 'name_key'],
                ['tier', 'name', 'code', 'heading', 'chapter', 'is_service', 'confidence', 'unit', 'category', 'meta', 'updated_at'],
            );
        }

        $count = count($deduped);
        $this->info("  {$source}: {$count} labels (from ".count($rows).' rows)');

        return $count;
    }

    /**
     * Load a sheet's data rows + a header→column-index map.
     *
     * @return array{0: array<int, array<int, mixed>>, 1: array<string, int>}
     */
    private function sheet(string $path, string $sheetName): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $sheet = $book->sheetNameExists($sheetName) ? $book->getSheetByName($sheetName) : $book->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);

        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($rows) ?? []);
        $col = [];
        foreach ($header as $i => $h) {
            $col[$h] = $i;
        }

        return [$rows, $col];
    }

    /**
     * First column index whose header contains any of the needles.
     *
     * @param  array<string, int>  $col
     * @param  array<int, string>  $needles
     */
    private function find(array $col, array $needles): int
    {
        foreach ($col as $header => $i) {
            foreach ($needles as $needle) {
                if (str_contains($header, $needle)) {
                    return $i;
                }
            }
        }

        return -1; // absent column → reads as null downstream
    }

    /** Left-pad a zero-stripped code back to 10 digits; null when not a 9–10 digit code. */
    private function normalizeCode(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);
        if ($digits === '' || mb_strlen($digits) < 9 || mb_strlen($digits) > 10) {
            return null;
        }

        return str_pad($digits, 10, '0', STR_PAD_LEFT);
    }

    private function str(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }

    private function bool(mixed $v): bool
    {
        return in_array(mb_strtolower(trim((string) $v)), ['true', '1', 'yes'], true);
    }
}
