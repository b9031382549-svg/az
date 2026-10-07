<?php

namespace App\Http\Controllers;

use App\Services\Import\InvoiceLinesImporter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The upload template: the header row of the line-level export ("Şablon") — the same fields
 * POST /api/classify takes. Only "Malın adı" is required (highlighted); every other column may
 * stay empty or be deleted, so a plain list of names is this file with one column filled in.
 * Built from InvoiceLinesImporter's headers, so the template always imports.
 */
class UploadTemplateController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $headers = InvoiceLinesImporter::headers();
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $name = Coordinate::stringFromColumnIndex((int) array_search('item_name', array_keys($headers), true) + 1);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Şablon');
        $sheet->fromArray([array_values($headers)], null, 'A1');
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->getStyle("{$name}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F6D58E');
        $sheet->freezePane('A2');
        for ($i = 1; $i <= count($headers); $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(22);
        }
        $sheet->getColumnDimension($name)->setWidth(48);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, 'Şablon.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
