<?php

namespace App\Services\Import;

use App\Models\ClassificationItem;
use App\Models\EInvoice;
use App\Models\ImportBatch;
use App\Models\ItemTranslation;
use App\Services\Classify\ClassificationQueue;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

// The line-level e-invoice export ("Şablon"): one row per invoice LINE with the invoice's own
// fields repeated on every line, plus the item name, the code the supplier declared, unit and
// quantity. Columns are found by their header text, not position, so a reordered or trimmed
// export still maps. A list of item names is the same thing with (almost) every column left out,
// so it is read here too. Every line lands in e_invoices (the chat's data) and every unique item
// name is classified right away — classification is what the upload is for.
class InvoiceLinesImporter
{
    /**
     * Lines a file may have to be previewed and imported inside the web request (20k lines read
     * in ~11 s / ~300 MB, measured). Bigger files go through BackgroundInvoiceUploads instead.
     */
    public const MAX_LINES = 20000;

    /** The layouts this importer reads: the e-invoice line export and a list of item names. */
    public const LAYOUTS = ['sablon', 'names'];

    /**
     * e_invoices column => the export's header text as the export writes it (the upload template
     * is built from it); a file's header is matched folded by headerKey().
     */
    private const HEADERS = [
        'supplier_tax_office' => 'e-QF təqdim edənin vergi orqanı',
        'supplier_name' => 'e-QF təqdim edənin adı',
        'supplier_tin' => 'e-QF təqdim edənin VÖEN',
        'recipient_tax_office' => 'e-QF əldə edənin Vergi orqanı',
        'recipient_name' => 'e-QF əldə edənin adı',
        'recipient_tin' => 'e-QF əldə edənin VÖEN',
        'invoice_type' => 'e-Qaimənin növü',
        'invoice_date' => 'e-Qaimənin tarixi',
        'approval_date' => 'e-Qaimənin təsdiq tarixi',
        'series' => 'e-Qaimənin seriyası',
        'number' => 'e-Qaimənin nömrəsi',
        'item_name' => 'Malın adı',
        'declared_group' => 'Qrup adı',
        'declared_code' => 'Kodu',
        'unit' => 'Malın ölçü vahidi',
        'quantity' => 'Malın miqdarı',
        'excise_amount' => 'Aksiz məbləği',
        'vat_taxable_amount' => 'ƏDV-yə cəlb edilən əməliyyatların məbləği',
        'non_vat_taxable_amount' => 'ƏDV-yə cəlb edilməyən əməliyyatların məbləği',
        'vat_exempt_amount' => 'ƏDV-dən azad olunan əməliyyatların məbləği',
        'zero_rated_vat_amount' => 'ƏDV-yə "0" dərəcə ilə cəlb edilən əməliyyatların məbləği',
        'vat_amount' => 'ƏDV məbləği',
        'road_tax' => 'Yol vergisi',
        'total_amount' => 'Yekun məbləğ',
    ];

    /**
     * Other headers lists of names put over the item-name column (the export says "Malın adı").
     * Compared by nameKey(), so "MƏHSULUN ADI", "Malin adi" and "ITEM" match too. Only names that
     * cannot mean anything else — a bare "Ad" / "Name" may well be a company's name.
     */
    private const NAME_HEADERS = [
        'Malın adı', 'Məhsulun adı', 'Mal (əmtəə) adı', 'Mal adı', 'Mal_adı',
        'Наименование', 'Наименование товара', 'Товар',
        'Item', 'Item name', 'Product', 'Product name',
    ];

    /**
     * Header words a one-column list may start with. Such a column is the names all the same,
     * but this first cell is a header, not an item to classify.
     */
    private const LIST_HEADERS = ['Ad', 'Adı', 'Adlar', 'Name', 'Names', 'Description', 'Название', 'Список'];

    private const DATE_COLS = ['invoice_date', 'approval_date'];

    private const DECIMAL_COLS = [
        'excise_amount', 'vat_taxable_amount', 'non_vat_taxable_amount', 'vat_exempt_amount',
        'zero_rated_vat_amount', 'vat_amount', 'road_tax', 'total_amount',
    ];

    /** Identifiers Excel may have turned into numbers — must come back as digit strings. */
    private const IDENTIFIER_COLS = ['supplier_tin', 'recipient_tin', 'series', 'number', 'declared_code'];

    /** Stored length caps (column sizes); any other text column is a varchar(255). */
    private const MAX_LENGTH = ['unit' => 64, 'declared_code' => 20, 'item_name' => 5000, 'declared_group' => 5000];

    /** Rows per insert (~30 columns → well under Postgres' bind-parameter cap). */
    private const INSERT_CHUNK = 1000;

    public function __construct(
        private readonly ClassificationQueue $queue,
        private readonly int $maxLines = self::MAX_LINES,
    ) {}

    /** @return array<int, string> the e_invoices columns a line can fill */
    public static function columns(): array
    {
        return array_keys(self::HEADERS);
    }

    /** @return array<string, string> e_invoices column => the export's header text, in export order */
    public static function headers(): array
    {
        return self::HEADERS;
    }

    /** Can this importer read a sheet that starts with this row? It needs an item-name column. */
    public static function matches(array $header): bool
    {
        return self::layout($header) !== null;
    }

    /**
     * 'sablon' — the line-level export (the item name plus an invoice column); 'names' — a list
     * of item names, maybe with a few line fields; null — no item-name column, not ours.
     */
    public static function layout(array $header): ?string
    {
        return self::shape($header)['layout'];
    }

    /**
     * How to read a sheet that starts with $header: the sheet column of every e_invoices column
     * found, the layout, and whether that first row is in fact the first name of a one-column
     * list that has no header row (then it is a line, not a header).
     *
     * @param  array<int, mixed>  $header
     * @return array{layout: ?string, map: array<string, int>, first_row_is_data: bool}
     */
    public static function shape(array $header): array
    {
        static $byHeader = null;
        $byHeader ??= array_flip(array_map(self::headerKey(...), self::HEADERS));

        $map = [];
        foreach ($header as $i => $cell) {
            $column = $byHeader[self::headerKey($cell)] ?? null;
            if ($column !== null && ! isset($map[$column])) {
                $map[$column] = $i;
            }
        }

        if (! isset($map['item_name'])) {
            $names = array_map(self::nameKey(...), self::NAME_HEADERS);
            foreach ($header as $i => $cell) {
                if (in_array(self::nameKey($cell), $names, true)) {
                    $map['item_name'] = $i;
                    break;
                }
            }
        }

        // One column under no header we know: that column is the names — its first cell
        // included, unless it is plainly a header word.
        $firstRowIsData = false;
        if ($map === [] && ($only = self::onlyFilledCell($header)) !== null) {
            $map['item_name'] = $only;
            $firstRowIsData = ! in_array(self::nameKey($header[$only]), array_map(self::nameKey(...), self::LIST_HEADERS), true);
        }

        $layout = match (true) {
            ! isset($map['item_name']) => null,
            isset($map['invoice_date']) || isset($map['total_amount']) => 'sablon',
            default => 'names',
        };

        return ['layout' => $layout, 'map' => $map, 'first_row_is_data' => $firstRowIsData];
    }

    /**
     * Inspect already-read rows without importing: line count, how many lines can be tied to
     * a specific invoice (and how many cannot), what is already in the database, and a sample.
     *
     * @param  array<int, mixed>  $header
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function previewRows(array $header, array $rows, string $checksum, int $limit = 8): array
    {
        $lines = $this->mapRows($header, $rows);

        if ($error = $this->sizeError(count($lines))) {
            // too_many: a streamable file this big is handed to the background path instead.
            return ['ok' => false, 'error' => $error, 'count' => count($lines), 'sample' => [], 'too_many' => count($lines) > $this->maxLines];
        }

        $stats = new InvoiceLineStats;
        foreach ($lines as $line) {
            $stats->add($line);
        }

        return $this->preview($stats, $checksum, array_slice($lines, 0, $limit))
            + ['first_row_is_data' => self::shape($header)['first_row_is_data']];
    }

    /**
     * The preview of a line-level export from its counted stats — shared by the in-request path
     * and the background reader: counts, duplicates of invoices already loaded, a re-upload of
     * the same file, and a sample.
     *
     * @param  array<int, array<string, mixed>>  $sample
     * @return array<string, mixed>
     */
    public function preview(InvoiceLineStats $stats, string $checksum, array $sample, ?string $exceptBatch = null): array
    {
        $counts = $stats->keyCounts();
        $known = EInvoice::existingKeys(array_keys($counts), $exceptBatch);
        $duplicates = array_sum(array_intersect_key($counts, $known));

        $same = ImportBatch::where('checksum', $checksum)
            ->whereNull('lines_deleted_at')
            ->when($exceptBatch !== null, fn ($q) => $q->where('key', '!=', $exceptBatch))
            ->where(fn ($q) => $q->whereNull('status')->orWhereIn('status', ['importing', 'imported']))
            ->latest('id')
            ->first();

        return [
            'ok' => true,
            'error' => null,
            'count' => $stats->toArray()['lines'],
            'stats' => $stats->toArray(),
            'duplicates' => $duplicates,
            'duplicate_invoices' => count($known),
            'same_file' => $same ? ['label' => $same->label, 'at' => $same->created_at?->toDateTimeString()] : null,
            'sample' => $sample,
        ];
    }

    /**
     * Import the lines as a new upload (import batch) and put every unique item name on the
     * classification pipeline. Lines, batch and items are written in ONE transaction; the jobs
     * are dispatched only after it commits.
     *
     * @param  array<int, mixed>  $header
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{imported: int, skipped: int, total: int, error: ?string, batch: ?string, items: int, stats: array<string, int>}
     */
    public function importRows(array $header, array $rows, string $checksum, string $label, ?int $userId, bool $skipDuplicates = false): array
    {
        $lines = $this->mapRows($header, $rows);

        if ($error = $this->sizeError(count($lines))) {
            return ['imported' => 0, 'skipped' => 0, 'total' => $this->total(), 'error' => $error, 'batch' => null, 'items' => 0, 'stats' => []];
        }

        // Duplicates are whole invoices already in the database (by series|number). Lines that
        // share a key WITHIN the file are simply the lines of one invoice; a line without a key
        // cannot be matched to anything, so it is never skipped.
        $known = $skipDuplicates ? EInvoice::existingKeys(array_filter(array_column($lines, 'invoice_key'))) : [];
        $keep = array_values(array_filter($lines, fn ($l) => $l['invoice_key'] === null || ! isset($known[$l['invoice_key']])));
        $skipped = count($lines) - count($keep);
        $stats = $this->stats($keep);

        if ($keep === []) {
            return ['imported' => 0, 'skipped' => $skipped, 'total' => $this->total(), 'error' => null, 'batch' => null, 'items' => 0, 'stats' => $stats];
        }

        $names = collect($keep)->pluck('item_name')->filter()
            ->unique(fn ($t) => ItemTranslation::hashFor($t))->values()->all();

        $batch = (string) Str::uuid();
        $format = self::layout($header) ?? 'sablon';

        try {
            $items = DB::transaction(function () use ($keep, $names, $batch, $format, $checksum, $label, $userId, $stats, $skipped) {
                ImportBatch::create([
                    'key' => $batch,
                    'label' => $label,
                    'source' => 'invoices',
                    'format' => $format,
                    'user_id' => $userId,
                    'item_count' => count($names),
                    'checksum' => $checksum,
                    'stats' => $stats + ['skipped' => $skipped],
                ]);

                return $this->writeLines($keep, $batch, false)['items'];
            });
        } catch (Throwable $e) {
            return ['imported' => 0, 'skipped' => 0, 'total' => $this->total(), 'error' => __('Import failed: :error', ['error' => $e->getMessage()]), 'batch' => null, 'items' => 0, 'stats' => []];
        }

        $this->queue->dispatch($items);

        return [
            'imported' => count($keep),
            'skipped' => $skipped,
            'total' => $this->total(),
            'error' => null,
            'batch' => $batch,
            'items' => $items->count(),
            'stats' => $stats,
        ];
    }

    /**
     * Write one portion of an upload's lines: skip whole invoices already loaded by OTHER
     * uploads (when asked — the upload's own earlier portions never count), create the
     * classification items for the portion's names, insert the lines linked to them. Runs in
     * the caller's transaction and dispatches nothing — the caller decides when to classify.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{imported: int, skipped: int, items: Collection<string, ClassificationItem>}
     */
    public function writeLines(array $lines, string $batch, bool $skipDuplicates): array
    {
        $known = $skipDuplicates
            ? EInvoice::existingKeys(array_filter(array_column($lines, 'invoice_key')), $batch)
            : [];
        $keep = array_values(array_filter($lines, fn ($l) => $l['invoice_key'] === null || ! isset($known[$l['invoice_key']])));

        $names = collect($keep)->pluck('item_name')->filter()
            ->unique(fn ($t) => ItemTranslation::hashFor($t))->values()->all();
        $items = $names ? $this->queue->createItems($names, $batch) : collect();

        $now = now();
        foreach (array_chunk($keep, self::INSERT_CHUNK) as $chunk) {
            DB::table('e_invoices')->insert(array_map(fn ($l) => $this->toRow($l, $batch, $items, $now), $chunk));
        }

        return ['imported' => count($keep), 'skipped' => count($lines) - count($keep), 'items' => $items];
    }

    /**
     * How much of the upload can be tied to specific invoices — the export does not always
     * carry VÖEN / series / number, and the reader has to know that.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, int>
     */
    private function stats(array $lines): array
    {
        $stats = new InvoiceLineStats;
        foreach ($lines as $line) {
            $stats->add($line);
        }

        return $stats->toArray();
    }

    private function sizeError(int $count): ?string
    {
        if ($count === 0) {
            return __('No invoice lines found in the file.');
        }
        if ($count > $this->maxLines) {
            return __('The file has :n lines — at most :max per upload for now. Split it into smaller files.', [
                'n' => number_format($count, 0, '.', ' '),
                'max' => number_format($this->maxLines, 0, '.', ' '),
            ]);
        }

        return null;
    }

    /** Header text folded for matching: Azerbaijani-aware lower case, quotes unified, spaces collapsed. */
    private static function headerKey(mixed $cell): string
    {
        // A streamed xlsx hands a date-formatted cell over as a DateTimeImmutable — never a header.
        $s = trim(preg_replace('/\s+/u', ' ', is_scalar($cell) ? (string) $cell : '') ?? '');
        $s = str_replace(['İ', 'I'], ['i', 'ı'], $s);
        $s = mb_strtolower($s, 'UTF-8');

        return str_replace(["\u{0307}", '“', '”', '„', '«', '»'], ['', '"', '"', '"', '"', '"'], $s);
    }

    /**
     * headerKey() with the dotless ı read as i — for the alternative item-name headers, which come
     * typed in many ways: "MALIN ADI" folds to "malın adı", "Malin adi" and "ITEM" do not.
     */
    private static function nameKey(mixed $cell): string
    {
        return str_replace('ı', 'i', self::headerKey($cell));
    }

    /** @param array<int, mixed> $header the position of its only non-blank cell, if it has exactly one */
    private static function onlyFilledCell(array $header): ?int
    {
        $filled = array_keys(array_filter($header, fn ($cell) => $cell !== null && trim(is_scalar($cell) ? (string) $cell : 'x') !== ''));

        return count($filled) === 1 ? (int) $filled[0] : null;
    }

    /**
     * Normalise every non-blank row into an e_invoices-shaped line.
     *
     * @param  array<int, mixed>  $header
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapRows(array $header, array $rows): array
    {
        if (self::shape($header)['first_row_is_data']) {
            array_unshift($rows, $header);
        }

        $map = $this->lineMapper($header);
        $lines = [];
        foreach ($rows as $i => $row) {
            if (($line = $map($row, $i + 1)) !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * A row → line mapper for this header (null for a blank row) — one row at a time, so a big
     * file can be read as a stream. $rowNo = the line's position in the file (the export's own
     * first column is just a 0-based index without a header).
     *
     * @param  array<int, mixed>  $header
     * @return Closure(array<int, mixed>, int): ?array<string, mixed>
     */
    public function lineMapper(array $header): Closure
    {
        $map = self::shape($header)['map'];

        return function (array $row, int $rowNo) use ($map): ?array {
            // Blank where it matters: a row of a names list with only, say, its group filled in
            // has nothing to keep.
            if ($this->isBlank(array_map(fn (int $i) => $row[$i] ?? null, $map))) {
                return null;
            }

            $line = ['row_no' => $rowNo];
            foreach (array_keys(self::HEADERS) as $column) {
                $line[$column] = $this->normalize($column, isset($map[$column]) ? ($row[$map[$column]] ?? null) : null);
            }

            // Same identity the legacy importer dedups on — series|number, or none at all.
            $line['invoice_key'] = ($line['series'] !== null && $line['number'] !== null)
                ? $line['series'].'|'.$line['number']
                : null;

            return $line;
        };
    }

    /**
     * The same line as lineMapper() builds, from values keyed by e_invoices column — for lines
     * that arrive as JSON (POST /api/classify) rather than as sheet rows.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function lineFromFields(array $fields, int $rowNo): array
    {
        $line = ['row_no' => $rowNo];
        foreach (array_keys(self::HEADERS) as $column) {
            $line[$column] = $this->normalize($column, $fields[$column] ?? null);
        }
        $line['invoice_key'] = ($line['series'] !== null && $line['number'] !== null)
            ? $line['series'].'|'.$line['number']
            : null;

        return $line;
    }

    private function normalize(string $column, mixed $value): mixed
    {
        if (in_array($column, self::DATE_COLS, true)) {
            return $this->date($value);
        }
        if (in_array($column, self::DECIMAL_COLS, true)) {
            return round($this->number($value) ?? 0.0, 2);
        }
        if ($column === 'quantity') {
            $n = $this->number($value);

            return $n === null ? null : round($n, 4);
        }

        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        if (in_array($column, self::IDENTIFIER_COLS, true) && (is_int($value) || is_float($value))) {
            $text = sprintf('%.0f', $value);
            // A 10-digit XİF MN code stored as a number loses its leading zero (0101… → 101…).
            if ($column === 'declared_code' && strlen($text) === 9) {
                $text = '0'.$text;
            }
        } else {
            $text = trim((string) $value);
        }

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, self::MAX_LENGTH[$column] ?? 255);
    }

    /** A date as Y-m-d: an Excel serial, or text — dd.mm.yyyy first (the export's format). */
    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) { // a date-formatted xlsx cell read as a stream
            return $value->format('Y-m-d');
        }
        if (is_int($value) || is_float($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        $text = trim((string) $value);
        foreach (['!d.m.Y', '!d.m.Y H:i:s', '!d.m.Y H:i', '!Y-m-d', '!Y-m-d H:i:s', '!d/m/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $text);
            } catch (Throwable) {
                continue;
            }
            if ($date !== false && $date->format(ltrim($format, '!')) === $text) {
                return $date->format('Y-m-d');
            }
        }

        $ts = strtotime($text);

        return $ts ? date('Y-m-d', $ts) : null;
    }

    /** A number from a numeric cell or text like "1 234,56"; null when there is none. */
    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if ($value === null || $value instanceof DateTimeInterface) {
            return null;
        }

        $text = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $value));

        return is_numeric($text) ? (float) $text : null;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  Collection<string, ClassificationItem>  $items  keyed by source_hash
     * @return array<string, mixed>
     */
    private function toRow(array $line, string $batch, Collection $items, Carbon $now): array
    {
        $hash = $line['item_name'] !== null ? ItemTranslation::hashFor($line['item_name']) : null;

        return $line + [
            'import_batch' => $batch,
            'classification_item_id' => $hash !== null ? ($items[$hash]->id ?? null) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
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

    private function total(): int
    {
        return (int) DB::table('e_invoices')->count();
    }
}
