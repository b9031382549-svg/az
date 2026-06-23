<?php

namespace Database\Seeders;

use App\Models\MetadataCatalogEntry;
use Illuminate\Database\Seeder;

class MetadataCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $table = 'e_invoices';

        // business_concept, column, type, role, description, aliases
        $entries = [
            ['Submitting taxpayer', 'supplier_tin', 'string', 'identifier',
                'TIN (VÖEN) of the e-invoice issuer / supplier (seller).',
                ['supplier', 'seller', 'issuer', 'sender', 'отправитель', 'поставщик', 'ИНН поставщика', 'satıcı', 'göndərən VÖEN']],
            ['Receiving taxpayer', 'recipient_tin', 'string', 'identifier',
                'TIN (VÖEN) of the e-invoice recipient / buyer (customer).',
                ['recipient', 'buyer', 'customer', 'получатель', 'покупатель', 'ИНН покупателя', 'alıcı VÖEN']],
            ['Invoice date', 'invoice_date', 'date', 'date',
                'Date the e-invoice was issued.',
                ['date', 'issued', 'invoice date', 'дата', 'дата счёта', 'tarix']],
            ['Approval date', 'approval_date', 'date', 'date',
                'Date the e-invoice was approved/confirmed.',
                ['approved', 'confirmation date', 'дата утверждения', 'təsdiq tarixi']],
            ['Invoice series', 'series', 'string', 'dimension',
                'e-invoice series code (e.g. MT2601).',
                ['series', 'seriya', 'серия']],
            ['Invoice number', 'number', 'string', 'identifier',
                'e-invoice sequential number.',
                ['number', 'no', 'nömrə', 'номер']],
            ['Excise amount', 'excise_amount', 'decimal', 'metric',
                'Excise tax amount on the invoice.',
                ['excise', 'aksiz', 'акциз']],
            ['VAT-taxable amount', 'vat_taxable_amount', 'decimal', 'metric',
                'Amount of VAT-taxable transactions.',
                ['vat taxable', 'taxable turnover', 'облагаемые НДС', 'ƏDV-yə cəlb olunan']],
            ['Non-VAT-taxable amount', 'non_vat_taxable_amount', 'decimal', 'metric',
                'Amount of non-VAT-taxable transactions.',
                ['non vat taxable', 'не облагаемые НДС']],
            ['VAT-exempt amount', 'vat_exempt_amount', 'decimal', 'metric',
                'Amount of VAT-exempt transactions.',
                ['vat exempt', 'освобождённые от НДС', 'ƏDV-dən azad']],
            ['Zero-rated VAT amount', 'zero_rated_vat_amount', 'decimal', 'metric',
                'Amount of zero-rated (0%) VAT transactions.',
                ['zero rated', 'нулевая ставка НДС', 'sıfır dərəcəli']],
            ['VAT amount', 'vat_amount', 'decimal', 'metric',
                'Value Added Tax (VAT/ƏDV) amount.',
                ['vat', 'ƏDV', 'НДС', 'tax']],
            ['Road tax', 'road_tax', 'decimal', 'metric',
                'Road tax amount.',
                ['road tax', 'yol vergisi', 'дорожный налог']],
            ['Turnover', 'total_amount', 'decimal', 'metric',
                'Total invoice amount. This is the turnover (dövriyyə / оборот).',
                ['turnover', 'total', 'total amount', 'оборот', 'сумма', 'итого', 'dövriyyə', 'cəmi', 'yekun məbləğ']],
        ];

        foreach ($entries as [$concept, $column, $type, $role, $desc, $aliases]) {
            MetadataCatalogEntry::updateOrCreate(
                ['table_name' => $table, 'column_name' => $column, 'business_concept' => $concept],
                ['data_type' => $type, 'role' => $role, 'description' => $desc, 'aliases' => $aliases, 'is_active' => true],
            );
        }
    }
}
