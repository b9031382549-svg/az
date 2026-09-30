<?php

namespace App\Services\Export;

use App\Models\ClassificationItem;
use App\Models\RubricatorNode;
use App\Services\Classify\DecisionSummary;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Builds an .xlsx of classification results: the item (good/service) and its
 * assigned classifier code in the adjacent column, plus context — and how the
 * code was found (method) and why this category (reason). Everything is written
 * in the CURRENT UI language only — the item name, the matched code name, the
 * headers and the labels; only the models' own words, quoted in the reason, stay
 * in English.
 */
class ClassificationExporter
{
    private const WIDTHS = ['A' => 6, 'B' => 46, 'C' => 16, 'D' => 10, 'E' => 46, 'F' => 11, 'G' => 14, 'H' => 22, 'I' => 80, 'J' => 24, 'K' => 16];

    public function __construct(private readonly DecisionSummary $summary) {}

    /**
     * @param  Collection<int, ClassificationItem>  $rows
     * @param  Collection<string, string>  $labels  batch key => upload label
     */
    public function build(Collection $rows, Collection $labels): Spreadsheet
    {
        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Classifications');

        $headers = ['#', __('Item'), __('Code'), __('Kind'), __('Matched name'), __('Confidence'), __('Status'), __('Method'), __('Reason'), __('Upload'), __('Date')];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        // Force the Code column to text so leading zeros (e.g. 0207) survive.
        $sheet->getStyle('C')->getNumberFormat()->setFormatCode('@');

        foreach (self::WIDTHS as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // A 4-digit heading (or "99") answer has no catalog leaf — resolve its name from
        // the rubricator, in the current locale (localizedTitle()).
        $headingNames = RubricatorNode::whereIn('code', $rows->pluck('final_code')
            ->filter(fn ($c) => ($n = mb_strlen((string) $c)) > 0 && $n < 10)->unique())
            ->get(['code', 'title', 'title_en', 'title_ru'])
            ->mapWithKeys(fn ($node) => [(string) $node->code => $node->localizedTitle()]);

        $summaries = $this->summary->forItems($rows);

        $i = 2;
        foreach ($rows->values() as $n => $row) {
            // Free-text fields are written as EXPLICIT strings so a value that starts with
            // =, +, -, @ can't be interpreted as an Excel formula (formula injection).
            $confidence = $row->finalConfidence();
            $codeName = $row->finalCode?->localizedName() ?: ($headingNames[(string) $row->final_code] ?? '');
            $summary = $summaries[$row->id] ?? null;

            $sheet->setCellValue("A{$i}", $n + 1);
            $sheet->setCellValueExplicit("B{$i}", $row->localizedSourceText(), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$i}", (string) ($row->final_code ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$i}", $row->kind ? __($row->kind) : '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$i}", (string) $codeName, DataType::TYPE_STRING);
            $sheet->setCellValue("F{$i}", $confidence !== null ? round((float) $confidence, 3) : null);
            $sheet->setCellValueExplicit("G{$i}", __(str_replace('_', ' ', (string) $row->resolution)), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("H{$i}", $summary ? DecisionSummary::label($summary['method']) : '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("I{$i}", $summary['reason'] ?? '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("J{$i}", (string) ($labels[$row->batch] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("K{$i}", optional($row->created_at)->format('Y-m-d H:i'));
            $i++;
        }

        $sheet->freezePane('A2');

        return $ss;
    }
}
