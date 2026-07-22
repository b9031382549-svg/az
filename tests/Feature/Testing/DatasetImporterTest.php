<?php

namespace Tests\Feature\Testing;

use App\Services\Testing\DatasetImporter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DatasetImporterTest extends TestCase
{
    public function test_parses_names_codes_recovers_leading_zero_and_flags_service(): void
    {
        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([
            ['Name', 'Code'],          // header — skipped (col A "name")
            ['Coffee beans', 901],     // NUMBER 901 => leading-zero lost => must recover to 0901
            ['Milk powder', '0402'],   // TEXT keeps its zero
            ['Consulting service', 99], // chapter 99 => service
            ['Broken row', 'n/a'],     // no digits => skipped
        ]);
        $path = tempnam(sys_get_temp_dir(), 'ds').'.xlsx';
        (new Xlsx($ss))->save($path);

        $rows = (new DatasetImporter)->rows($path);
        @unlink($path);

        $this->assertCount(4, $rows);

        // 901 -> 0901 (the whole point: chapter 01-09 goods must not score 0%)
        $this->assertSame('0901', $rows[0]['expected_heading']);
        $this->assertSame('0901', $rows[0]['expected_code']);
        $this->assertFalse($rows[0]['expected_is_service']);
        $this->assertNull($rows[0]['skip_reason']);

        $this->assertSame('0402', $rows[1]['expected_heading']);

        $this->assertTrue($rows[2]['expected_is_service']);
        $this->assertSame('99', $rows[2]['expected_heading']);

        $this->assertNotNull($rows[3]['skip_reason']); // "n/a" -> no usable code
        $this->assertNull($rows[3]['expected_heading']);
    }

    public function test_picks_the_items_sheet_over_an_active_summary_sheet(): void
    {
        $ss = new Spreadsheet;

        // The ACTIVE/first tab is a summary/readme (what tripped the real upload).
        $summary = $ss->getActiveSheet();
        $summary->setTitle('Summary');
        $summary->fromArray([
            ['Claude labeled', 1200],
            ['Agreement rate', 90],   // too short -> skipped
            ['Note', 'see items tab'], // no usable code -> skipped
        ]);

        // The real items live on a second tab.
        $items = $ss->createSheet();
        $items->setTitle('Items');
        $data = [['Name', 'Code']];
        for ($i = 1; $i <= 30; $i++) {
            $data[] = ["Product {$i}", 900 + $i];
        }
        $items->fromArray($data);

        $ss->setActiveSheetIndex(0); // summary is active — the importer must NOT trust this

        $path = tempnam(sys_get_temp_dir(), 'ds').'.xlsx';
        (new Xlsx($ss))->save($path);
        $rows = (new DatasetImporter)->rows($path);
        @unlink($path);

        // The 30-row Items sheet wins over the 1-usable-row Summary sheet.
        $this->assertCount(30, $rows);
        $this->assertSame('Product 1', $rows[0]['source_text']);
    }
}
