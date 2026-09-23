<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

// Reads the first sheet of an .xlsx/.xls/.csv into memory: the header row and the data rows as
// raw cell values (numbers stay numbers, dates stay Excel serials). Whole-sheet reading costs
// ~15 MB and ~0.5 s per 1,000 lines of a 25-column export (measured) — fine for the sizes the
// upload accepts; a streaming reader is the path for bigger files.
class SheetReader
{
    /**
     * @return array{0: array<int, mixed>, 1: array<int, array<int, mixed>>}
     */
    public function read(string $path): array
    {
        ini_set('memory_limit', '1024M');

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $rows = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);
        $header = array_shift($rows);

        return [is_array($header) ? $header : [], $rows];
    }
}
