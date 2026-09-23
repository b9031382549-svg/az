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
            $delimiter = $this->delimiter($first);

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

    /** The separator the header line uses most: comma, semicolon (Excel in AZ/RU locales) or tab. */
    private function delimiter(string $header): string
    {
        $counts = [',' => substr_count($header, ','), ';' => substr_count($header, ';'), "\t" => substr_count($header, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
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
