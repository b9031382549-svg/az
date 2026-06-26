<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

class ItemFileParser
{
    /** Header-ish cells to skip (case-insensitive). */
    private const HEADERS = [
        'məhsulun adi', 'məhsulun adı', 'mal_adi', 'mal adı', 'mal adi', 'mal/xİdmət',
        'mal/xidmət', 'group', 'aİ qrup', 'ai qrup', 'name', 'ad', 'adı', 'item', 'description',
    ];

    /**
     * Extract item names from an .xlsx/.xls/.csv: the first non-empty, non-numeric
     * text cell of each row (handles the name being in different columns across
     * the goods/services sample files). Skips headers and tiny cells.
     *
     * @return array<int, string>
     */
    public function parse(string $path, int $limit = 200): array
    {
        ini_set('memory_limit', '1024M');

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $rows = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);

        $items = [];
        foreach ($rows as $row) {
            $text = $this->firstText($row);
            if ($text === '' || mb_strlen($text) < 3) {
                continue;
            }
            if (in_array(mb_strtolower($text), self::HEADERS, true)) {
                continue;
            }
            $items[] = $text;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /** Count all usable item rows (without the limit) — for "queued N of M". */
    public function count(string $path): int
    {
        return count($this->parse($path, PHP_INT_MAX));
    }

    private function firstText(array $row): string
    {
        foreach ($row as $cell) {
            $s = trim((string) $cell);
            if ($s !== '' && ! is_numeric($s)) {
                return $s;
            }
        }

        return '';
    }
}
