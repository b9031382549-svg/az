<?php

namespace App\Services\Testing;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Parses a 2-column dataset spreadsheet (column A = item name, column B = correct
 * code) into labelled rows. We score at the 4-digit HS heading, so expected_heading
 * and expected_is_service are derived here once.
 *
 * Sheet selection: workbooks often carry a summary/readme tab as the ACTIVE sheet with
 * the real items on another tab — so we scan EVERY worksheet and keep the one that
 * yields the most usable rows (rather than trusting getActiveSheet()).
 *
 * Leading-zero recovery: xlsx stores a code like "0901" (coffee, chapter 09) as the
 * NUMBER 901, which loses the chapter's leading zero. HS codes are even-length, so an
 * odd digit-count means exactly one chapter-zero was dropped — we restore it. Without
 * this, every chapter 01–09 good (the food/agri bulk of these invoices) scores 0%.
 */
class DatasetImporter
{
    /** Header-ish first-column cells to skip (case-insensitive). */
    private const HEADERS = [
        'məhsulun adi', 'məhsulun adı', 'mal_adi', 'mal adı', 'mal adi', 'name', 'ad', 'adı',
        'item', 'description', 'ad/xidmət', 'mal/xidmət', 'kod', 'code', 'hs', 'xif',
    ];

    /**
     * @return array<int, array{source_text:string, expected_code:?string, expected_heading:?string, expected_is_service:bool, skip_reason:?string}>
     */
    public function rows(string $path, int $limit = 10000): array
    {
        ini_set('memory_limit', '1024M');

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        // Keep the sheet with the most USABLE (non-skipped) rows — a summary/readme tab
        // that happens to be active never wins over the real items tab.
        $best = [];
        $bestValid = -1;
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $rows = $this->parseSheet($sheet, $limit);
            $valid = 0;
            foreach ($rows as $r) {
                if ($r['skip_reason'] === null) {
                    $valid++;
                }
            }
            if ($valid > $bestValid) {
                $bestValid = $valid;
                $best = $rows;
            }
        }

        return $best;
    }

    /**
     * @return array<int, array{source_text:string, expected_code:?string, expected_heading:?string, expected_is_service:bool, skip_reason:?string}>
     */
    private function parseSheet(Worksheet $sheet, int $limit): array
    {
        // formatData=false → raw cell values (numeric codes come back as int/float,
        // which is exactly what we need for the leading-zero recovery below).
        $grid = $sheet->toArray(null, true, false, false);

        $out = [];
        foreach ($grid as $row) {
            $name = trim((string) ($row[0] ?? ''));
            if ($name === '' || mb_strlen($name) < 2) {
                continue;
            }
            if (in_array(mb_strtolower($name), self::HEADERS, true)) {
                continue;
            }

            $code = $this->normalizeCode($row[1] ?? null);
            $isService = $code !== null && str_starts_with($code, '99');

            [$heading, $skip] = match (true) {
                $isService => ['99', null],
                $code === null => [null, 'no code in column B'],
                mb_strlen($code) < 4 => [null, "code '{$code}' shorter than a 4-digit heading"],
                default => [mb_substr($code, 0, 4), null],
            };

            $out[] = [
                'source_text' => $name,
                'expected_code' => $code,
                'expected_heading' => $heading,
                'expected_is_service' => $isService,
                'skip_reason' => $skip,
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Turn a raw code cell into a clean digit string, restoring a single dropped
     * leading chapter-zero. Returns null when the cell holds no digits.
     */
    private function normalizeCode(mixed $cell): ?string
    {
        if ($cell === null) {
            return null;
        }

        // A numeric cell (int/float) is the leading-zero-loss case; a text cell keeps
        // its zeros, but strip any stray non-digits (spaces, dots) defensively.
        $digits = (is_int($cell) || is_float($cell))
            ? (string) (int) round((float) $cell)
            : (string) preg_replace('/\D+/', '', (string) $cell);

        if ($digits === '') {
            return null;
        }

        // HS codes are even-length (2/4/6/8/10). An odd count => one chapter-zero was
        // dropped by numeric coercion (chapters 01–09); prepend it back.
        if (mb_strlen($digits) % 2 === 1) {
            $digits = '0'.$digits;
        }

        return $digits;
    }
}
