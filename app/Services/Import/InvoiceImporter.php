<?php

namespace App\Services\Import;

use App\Models\EInvoice;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

// The legacy invoice list: one row per invoice, 15 columns in a fixed order (mapped by
// position). The line-level export has its own importer (InvoiceLinesImporter).
class InvoiceImporter
{
    /** Column order expected in the invoice export. */
    public const COLUMNS = [
        'row_no', 'supplier_tin', 'recipient_tin', 'invoice_date', 'approval_date',
        'series', 'number', 'excise_amount', 'vat_taxable_amount', 'non_vat_taxable_amount',
        'vat_exempt_amount', 'zero_rated_vat_amount', 'vat_amount', 'road_tax', 'total_amount',
    ];

    private const DATE_COLS = ['invoice_date', 'approval_date'];

    private const DECIMAL_COLS = [
        'excise_amount', 'vat_taxable_amount', 'non_vat_taxable_amount', 'vat_exempt_amount',
        'zero_rated_vat_amount', 'vat_amount', 'road_tax', 'total_amount',
    ];

    public function __construct(
        private readonly SheetReader $reader,
    ) {}

    /**
     * Inspect a file without importing: validate the header and return a sample.
     *
     * @return array{ok: bool, error: ?string, count: int, duplicates: int, header: array<int,mixed>, sample: array<int, array<string,mixed>>}
     */
    public function preview(string $path, int $limit = 8): array
    {
        try {
            [$header, $rows] = $this->reader->read($path);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => __('Cannot read file: :error', ['error' => $e->getMessage()]), 'count' => 0, 'duplicates' => 0, 'header' => [], 'sample' => []];
        }

        return $this->previewRows($header, $rows, $limit);
    }

    /**
     * preview() over rows already read — the upload page reads a file once to recognise its
     * layout, then previews it with the matching importer.
     *
     * @param  array<int, mixed>  $header
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{ok: bool, error: ?string, count: int, duplicates: int, header: array<int,mixed>, sample: array<int, array<string,mixed>>}
     */
    public function previewRows(array $header, array $rows, int $limit = 8): array
    {
        $ok = is_array($header) && count($header) >= count(self::COLUMNS);
        $count = 0;
        $duplicates = 0;
        $sample = [];
        $existingKeys = $this->existingKeys();

        foreach ($rows as $row) {
            if ($this->isBlank($row)) {
                continue;
            }
            $count++;
            $mapped = $this->mapRow($row);
            $key = $this->naturalKey($mapped);
            if ($key !== null) {
                if (isset($existingKeys[$key])) {
                    $duplicates++;
                } else {
                    $existingKeys[$key] = true;
                }
            }
            if (count($sample) < $limit) {
                $sample[] = $mapped;
            }
        }

        return [
            'ok' => $ok,
            'error' => $ok ? null : __('Unexpected columns: expected at least :min, got :got.', ['min' => count(self::COLUMNS), 'got' => is_array($header) ? count($header) : 0]),
            'count' => $count,
            'duplicates' => $duplicates,
            'header' => is_array($header) ? $header : [],
            'sample' => $sample,
        ];
    }

    /**
     * Import every non-blank row into e_invoices.
     *
     * @return array{imported: int, skipped: int, total: int, error: ?string}
     */
    public function import(string $path, bool $skipDuplicates = false, ?string $batch = null): array
    {
        try {
            [$header, $rows] = $this->reader->read($path);
        } catch (Throwable $e) {
            return ['imported' => 0, 'skipped' => 0, 'total' => $this->total(), 'error' => __('Cannot read file: :error', ['error' => $e->getMessage()])];
        }

        return $this->importRows($header, $rows, $skipDuplicates, $batch);
    }

    /**
     * import() over rows already read. $batch stamps every row with the upload it came from
     * (import_batches.key), so that upload can be deleted on its own later.
     *
     * @param  array<int, mixed>  $header
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{imported: int, skipped: int, total: int, error: ?string}
     */
    public function importRows(array $header, array $rows, bool $skipDuplicates = false, ?string $batch = null): array
    {
        if (! is_array($header) || count($header) < count(self::COLUMNS)) {
            return ['imported' => 0, 'skipped' => 0, 'total' => $this->total(), 'error' => __('Unexpected columns in file.')];
        }

        $mapped = [];
        foreach ($rows as $row) {
            if (! $this->isBlank($row)) {
                $mapped[] = $this->mapRow($row);
            }
        }
        ['imported' => $imported, 'skipped' => $skipped] = $this->writeRows($mapped, $batch, $skipDuplicates);

        return ['imported' => $imported, 'skipped' => $skipped, 'total' => $this->total(), 'error' => null];
    }

    /** Wipe every invoice row. The only place this class truncates. */
    public function deleteAll(): int
    {
        $count = $this->total();
        DB::table('e_invoices')->truncate();

        return $count;
    }

    /**
     * Insert mapped rows (one portion of an upload, or all of a small one). With
     * $skipDuplicates a row whose series|number is already in the table — from any upload,
     * this one's earlier portions included — or earlier in these rows is skipped, so only the
     * first occurrence of an invoice is kept.
     *
     * @param  array<int, array<string, mixed>>  $mapped
     * @return array{imported: int, skipped: int}
     */
    public function writeRows(array $mapped, ?string $batch, bool $skipDuplicates): array
    {
        $existingKeys = $skipDuplicates
            ? EInvoice::existingKeys(array_filter(array_map(fn ($r) => $this->naturalKey($r), $mapped)))
            : [];

        $now = Carbon::now();
        $buffer = [];
        $imported = 0;
        $skipped = 0;

        foreach ($mapped as $record) {
            $key = $this->naturalKey($record);

            if ($skipDuplicates && $key !== null && isset($existingKeys[$key])) {
                $skipped++;

                continue;
            }

            if ($key !== null) {
                $existingKeys[$key] = true;
            }

            $buffer[] = $record + [
                'invoice_key' => $key,
                'import_batch' => $batch,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $imported++;

            if (count($buffer) >= 1000) {
                DB::table('e_invoices')->insert($buffer);
                $buffer = [];
            }
        }
        if ($buffer) {
            DB::table('e_invoices')->insert($buffer);
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * One raw row → an e_invoices record (by position).
     *
     * @return array<string, mixed>
     */
    public function mapRow(array $row): array
    {
        $record = [];
        foreach (self::COLUMNS as $i => $col) {
            $record[$col] = $this->normalize($col, $row[$i] ?? null);
        }

        return $record;
    }

    private function total(): int
    {
        return (int) DB::table('e_invoices')->count();
    }

    /** Natural business key for de-duplication: series+number. Null when either is missing. */
    public function naturalKey(array $mapped): ?string
    {
        $series = $mapped['series'] ?? null;
        $number = $mapped['number'] ?? null;

        if (! is_string($series) || $series === '' || ! is_string($number) || $number === '') {
            return null;
        }

        return $series.'|'.$number;
    }

    /** @return array<string, true> */
    private function existingKeys(): array
    {
        $keys = [];

        DB::table('e_invoices')
            ->select('series', 'number')
            ->whereNotNull('series')
            ->where('series', '!=', '')
            ->whereNotNull('number')
            ->where('number', '!=', '')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$keys) {
                foreach ($rows as $row) {
                    $keys[$row->series.'|'.$row->number] = true;
                }
            });

        return $keys;
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
            if ($value instanceof DateTimeInterface) { // a date-formatted xlsx cell read as a stream
                return $value->format('Y-m-d');
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

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        return $value === null ? null : trim((string) $value);
    }
}
