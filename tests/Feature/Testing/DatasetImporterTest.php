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
}
