<?php

namespace Tests\Unit;

use App\Services\Classify\TrashFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrashFilterTest extends TestCase
{
    /** @return array<string, array{string, string}> real TRASH names from the labelled sample */
    public static function trash(): array
    {
        return [
            'only digits' => ['646', 'no_letters'],
            'digits and punctuation' => ['061.760', 'no_letters'],
            'underscores and a number' => ['_____ 7 ____', 'no_letters'],
            'plate with dashes' => ['99-JM-812', 'car_plate'],
            'plate glued' => ['90RM248', 'car_plate'],
            'e-mail' => ['chinara.m30@gmail.com', 'email'],
            'company' => ['POLAR BOYA MMC', 'company'],
            'company in quotes' => ['"AZNUR" MMC ', 'company'],
            'invoice reference' => ['26.02.2018 tarixli 001/IE/18 nomreli hesaba əsasən', 'paperwork'],
            'upper case, no diacritics' => ['05.02.2018-CI IL TARIXLI 12/18 SAYLI HESABA ESASEN', 'paperwork'],
            'contract alone' => ['Müqaviləyə əsasən', 'paperwork'],
            'letter number' => ['11.03.2021-ci il tarixli 1033/I nömrəli məktub', 'paperwork'],
            'month' => ['Fevral ayı', 'paperwork'],
            'period' => ['2021-ci ilin iyun,iyul,avqust,sentyabr ayları üçün', 'paperwork'],
            'quarter' => ['IV rüb 2020-ci il üçün', 'paperwork'],
            'russian month' => ['за январь 2020', 'paperwork'],
            'receipt' => ['23.11.2020 qəbz № 7162', 'paperwork'],
        ];
    }

    /** @return array<string, array{string}> goods / services that must reach the classifier */
    public static function products(): array
    {
        return [
            // The same paperwork wording, but it says WHAT: a fee for a service.
            'service by contract' => ['17/029 saylı müqavilə çərçivəsində Fevral ayı üçün xidmət haqqı'],
            'service for a month' => ['mart xidmet'],
            // Words that start like a month.
            'mayonnaise' => ['Mayonez 400 qr'],
            'yeast' => ['Maya 0,500'],
            'martini' => ['Martini Bianco 1L'],
            'martini, cyrillic' => ['Мартини 1 л'],
            // Codes that ARE products.
            'petrol' => ['A-92'],
            'engine oil' => ['15w40'],
            'machine model' => ['JCB290'],
            // Brands that look like surnames — left to the pipeline, never trashed.
            'chocolate brand' => ['Babayev şokolad'],
            'cigarette brand' => ['Davidov nazik'],
            // A product line that names its supplier.
            'fuel from a company' => ['BENZIN_A-92_SOCAR(SUN FOOD MMC)'],
            'ordinary item' => ['SPRITE PET-2 LT 1X6'],
        ];
    }

    #[DataProvider('trash')]
    public function test_a_name_that_names_no_product_is_trash(string $name, string $rule): void
    {
        $this->assertSame($rule, (new TrashFilter)->reason($name));
    }

    #[DataProvider('products')]
    public function test_a_product_or_service_is_never_trash(string $name): void
    {
        $this->assertNull((new TrashFilter)->reason($name));
    }
}
