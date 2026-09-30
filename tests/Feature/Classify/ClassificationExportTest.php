<?php

namespace Tests\Feature\Classify;

use App\Models\ClassificationItem;
use App\Models\RubricatorNode;
use App\Services\Export\ClassificationExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassificationExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_exports_one_language_column_and_rubricator_name_for_a_4digit_answer(): void
    {
        app()->setLocale('ru');
        RubricatorNode::create(['code' => '9018', 'level' => 2, 'kind' => 'good', 'title' => 'AZ', 'title_en' => 'EN name', 'title_ru' => 'РУ название']);
        $item = ClassificationItem::create(['batch' => 'b', 'source_text' => 'Şpris', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '9018', 'kind' => 'good']);

        $sheet = app(ClassificationExporter::class)->build(collect([$item]), collect())->getActiveSheet();

        // One localized "Item" column: Code sits in column C (not a third item-language column).
        $this->assertSame(__('Code'), $sheet->getCell('C1')->getValue());
        $this->assertSame('9018', $sheet->getCell('C2')->getValue());
        // The 4-digit answer's name comes from the rubricator, in the current locale.
        $this->assertSame('РУ название', $sheet->getCell('E2')->getValue());
    }

    public function test_exports_how_the_code_was_found_and_why(): void
    {
        app()->setLocale('ru');
        $item = ClassificationItem::create(['batch' => 'b', 'source_text' => 'UN KARMEN 10KQ', 'source_hash' => bin2hex(random_bytes(16)), 'resolution' => 'agreed', 'final_code' => '1101', 'kind' => 'good']);
        $item->results()->create(['mechanism' => 'cache', 'matched_code' => '1101', 'status' => 'auto_confirmed', 'confidence' => 1.0, 'explanation' => 'Verified answer from the cache (auto:consensus).']);

        $sheet = app(ClassificationExporter::class)->build(collect([$item->load('results')]), collect(['b' => 'ECR']))->getActiveSheet();

        $this->assertSame(['Метод', 'Причина', 'Загрузка', 'Дата'], [
            $sheet->getCell('H1')->getValue(), $sheet->getCell('I1')->getValue(), $sheet->getCell('J1')->getValue(), $sheet->getCell('K1')->getValue(),
        ]);
        $this->assertSame('Память', $sheet->getCell('H2')->getValue());
        $this->assertSame('Название совпало с проверенным ответом из памяти (источник: прежнее единогласное решение ИИ).', $sheet->getCell('I2')->getValue());
        $this->assertSame('ECR', $sheet->getCell('J2')->getValue());
    }
}
