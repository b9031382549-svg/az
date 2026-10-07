<?php

namespace App\Services\Import;

use Generator;
use InvalidArgumentException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

// Reads the first sheet of an .xlsx or .csv ROW BY ROW in constant memory — OpenSpout for xlsx,
// fgetcsv for csv. Measured on the 25-column line-level export: 100k lines in ~24 s / 13 MB
// (xlsx) and ~0.6 s (csv), where the whole-sheet SheetReader needs ~1.5 GB. The first row yielded
// is the header; blank rows are skipped. Cells come raw: numbers stay numbers, a date-formatted
// xlsx cell comes as a DateTimeImmutable, csv cells are strings.
class SheetStream
{
    /** Extensions that can be streamed (the old binary .xls cannot). */
    public const EXTENSIONS = ['xlsx', 'csv'];

    /** The "separator" of a one-column csv — a byte no text holds, so a line is never split. */
    private const NO_SPLIT = "\x1F";

    /** @return Generator<int, array<int, mixed>> */
    public function rows(string $path, string $extension): Generator
    {
        $extension = strtolower($extension);
        if ($extension === 'xlsx') {
            yield from $this->xlsx($path);
        } elseif ($extension === 'csv') {
            yield from $this->csv($path);
        } else {
            throw new InvalidArgumentException("Cannot stream a .{$extension} file.");
        }
    }

    /** @return Generator<int, array<int, mixed>> */
    private function xlsx(string $path): Generator
    {
        $reader = new XlsxReader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();
                    if (! $this->isBlank($cells)) {
                        yield $cells;
                    }
                }

                break; // the first sheet only, like the whole-sheet reader
            }
        } finally {
            $reader->close();
        }
    }

    /** @return Generator<int, array<int, mixed>> */
    private function csv(string $path): Generator
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new InvalidArgumentException("Cannot open {$path}.");
        }

        try {
            $first = fgets($fh);
            if ($first === false) {
                return;
            }
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first; // UTF-8 BOM
            $delimiter = $this->delimiter($first, $this->peek($fh));

            yield str_getcsv(rtrim($first, "\r\n"), $delimiter, '"', '');

            while (($cells = fgetcsv($fh, null, $delimiter, '"', '')) !== false) {
                if (! $this->isBlank($cells)) {
                    yield $cells;
                }
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * The separator, judged on the first line outside quotes: a semicolon (Excel in AZ/RU
     * locales) or a tab, whichever it holds more of; else a comma — but only when most of the
     * next lines hold as many commas as the first. Item names carry commas ("PIVƏ 1,0 LT PET"),
     * so a one-column list of names — with or without a header row — must not be split on them.
     *
     * @param  array<int, string>  $next  a few lines after the first
     */
    private function delimiter(string $first, array $next): string
    {
        $counts = [';' => self::countOutsideQuotes($first, ';'), "\t" => self::countOutsideQuotes($first, "\t")];
        arsort($counts);
        if (reset($counts) > 0) {
            return (string) array_key_first($counts);
        }

        $commas = self::countOutsideQuotes($first, ',');
        if ($commas === 0) {
            return self::NO_SPLIT;
        }
        $same = count(array_filter($next, fn (string $line) => self::countOutsideQuotes($line, ',') === $commas));

        return $next === [] || $same * 2 >= count($next) ? ',' : self::NO_SPLIT;
    }

    /**
     * Up to $lines lines after the current position, which is then restored.
     *
     * @param  resource  $fh
     * @return array<int, string>
     */
    private function peek($fh, int $lines = 20): array
    {
        $at = ftell($fh);
        $out = [];
        while (count($out) < $lines && ($line = fgets($fh)) !== false) {
            if (trim($line) !== '') {
                $out[] = $line;
            }
        }
        fseek($fh, (int) $at);

        return $out;
    }

    private static function countOutsideQuotes(string $line, string $separator): int
    {
        $n = 0;
        $quoted = false;
        for ($i = 0, $len = strlen($line); $i < $len; $i++) {
            if ($line[$i] === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && $line[$i] === $separator) {
                $n++;
            }
        }

        return $n;
    }

    private function isBlank(array $cells): bool
    {
        foreach ($cells as $v) {
            if ($v !== null && $v !== '') {
                return false;
            }
        }

        return true;
    }
}
