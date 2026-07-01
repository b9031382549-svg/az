<?php

namespace App\Services\Export;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Builds an .xlsx of classification results: the item (good/service) and its
 * assigned classifier code in the adjacent column, plus context.
 */
class ClassificationExporter
{
    private const HEADERS = ['#', 'Item', 'Item (EN)', 'Item (RU)', 'Code', 'Kind', 'Matched name', 'Confidence', 'Status', 'Upload', 'Date'];

    private const WIDTHS = ['A' => 6, 'B' => 46, 'C' => 46, 'D' => 46, 'E' => 16, 'F' => 10, 'G' => 46, 'H' => 11, 'I' => 14, 'J' => 24, 'K' => 16];

    /**
     * @param  Collection<int, \App\Models\Classification>  $rows
     * @param  Collection<string, string>  $labels  batch key => upload label
     */
    public function build(Collection $rows, Collection $labels): Spreadsheet
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Classifications');

        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        // Force the Code column to text so leading zeros (e.g. 0207606100) survive.
        $sheet->getStyle('E')->getNumberFormat()->setFormatCode('@');

        foreach (self::WIDTHS as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        $i = 2;
        foreach ($rows->values() as $n => $row) {
            // Free-text fields are written as EXPLICIT strings so a value that
            // starts with =, +, -, @ can't be interpreted as an Excel formula
            // (CSV/spreadsheet formula injection).
            $sheet->setCellValue("A{$i}", $n + 1);
            $sheet->setCellValueExplicit("B{$i}", (string) $row->source_text, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$i}", (string) ($row->translation?->en ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$i}", (string) ($row->translation?->ru ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$i}", (string) ($row->matched_code ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("F{$i}", (string) ($row->kind ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("G{$i}", (string) ($row->code?->localizedName() ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("H{$i}", $row->confidence !== null ? round((float) $row->confidence, 3) : null);
            $sheet->setCellValue("I{$i}", str_replace('_', ' ', (string) $row->status));
            $sheet->setCellValueExplicit("J{$i}", (string) ($labels[$row->batch] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("K{$i}", optional($row->created_at)->format('Y-m-d H:i'));
            $i++;
        }

        $sheet->freezePane('A2');

        return $ss;
    }
}
