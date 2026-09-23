<?php

namespace Database\Seeders;

use App\Models\MetadataCatalogEntry;
use Illuminate\Database\Seeder;

// Business meaning of every column the AI chat can see (the invoice_lines view): descriptions
// and multilingual aliases the NL→SQL prompt is enriched with. Idempotent — the deploy re-runs it.
class MetadataCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $table = 'invoice_lines';

        // business_concept, column, type, role, description, aliases
        $entries = [
            ['Submitting taxpayer', 'supplier_tin', 'string', 'identifier',
                'TIN (VÖEN) of the e-invoice issuer / supplier (seller). May be NULL in line-level exports.',
                ['supplier', 'seller', 'issuer', 'sender', 'отправитель', 'поставщик', 'ИНН поставщика', 'satıcı', 'göndərən VÖEN']],
            ['Supplier name', 'supplier_name', 'string', 'dimension',
                'Name of the supplier (seller). May be NULL.',
                ['supplier name', 'seller name', 'название поставщика', 'təqdim edənin adı']],
            ['Supplier tax office', 'supplier_tax_office', 'string', 'dimension',
                'Tax authority the supplier is registered with (e.g. LGBİ).',
                ['tax office', 'налоговый орган поставщика', 'vergi orqanı']],
            ['Receiving taxpayer', 'recipient_tin', 'string', 'identifier',
                'TIN (VÖEN) of the e-invoice recipient / buyer (customer). May be NULL in line-level exports.',
                ['recipient', 'buyer', 'customer', 'получатель', 'покупатель', 'ИНН покупателя', 'alıcı VÖEN']],
            ['Recipient name', 'recipient_name', 'string', 'dimension',
                'Name of the recipient (buyer). May be NULL.',
                ['buyer name', 'customer name', 'название покупателя', 'əldə edənin adı']],
            ['Recipient tax office', 'recipient_tax_office', 'string', 'dimension',
                'Tax authority the recipient is registered with.',
                ['налоговый орган покупателя', 'əldə edənin vergi orqanı']],
            ['Invoice date', 'invoice_date', 'date', 'date',
                'Date the e-invoice was issued.',
                ['date', 'issued', 'invoice date', 'дата', 'дата счёта', 'tarix']],
            ['Approval date', 'approval_date', 'date', 'date',
                'Date the e-invoice was approved/confirmed.',
                ['approved', 'confirmation date', 'дата утверждения', 'təsdiq tarixi']],
            ['Invoice type', 'invoice_type', 'string', 'dimension',
                "Type of the e-invoice: 'Malların(işlərin,xidmətlərin) təqdim edilməsi' = supply of goods/works/services; 'Alınmış avans ödənişləri barədə' = advance payment received. NULL for invoice-list rows.",
                ['type', 'advance', 'аванс', 'тип накладной', 'növ', 'avans']],
            ['Invoice series', 'series', 'string', 'dimension',
                'e-invoice series code (e.g. MT2601).',
                ['series', 'seriya', 'серия']],
            ['Invoice number', 'number', 'string', 'identifier',
                'e-invoice sequential number.',
                ['number', 'no', 'nömrə', 'номер']],
            ['Invoice identity', 'invoice_key', 'string', 'identifier',
                'series|number — the invoice a line belongs to. Count invoices as COUNT(DISTINCT invoice_key). NULL = the line cannot be tied to a specific invoice (the export had no series/number).',
                ['invoice', 'invoice id', 'накладная', 'счёт', 'qaimə']],
            ['Item name', 'item_name', 'string', 'dimension',
                'Name of the goods/service on the invoice line. NULL for invoice-list rows (whole invoices without line detail).',
                ['item', 'goods', 'product', 'service', 'товар', 'услуга', 'позиция', 'наименование', 'mal', 'malın adı', 'xidmət']],
            ['Unit of measure', 'unit', 'string', 'dimension',
                'Unit as written on the invoice (ədəd, kq, ay, xidmət …). Case varies (ədəd / ƏDƏD) — compare lower(unit).',
                ['unit', 'единица измерения', 'ölçü vahidi']],
            ['Quantity', 'quantity', 'decimal', 'metric',
                'Quantity of the item on the line.',
                ['qty', 'quantity', 'количество', 'miqdar']],
            ['Declared code', 'declared_code', 'string', 'dimension',
                'The 10-digit XİF MN code the SUPPLIER put on the invoice line — the supplier\'s claim, not verified (often wrong).',
                ['declared code', 'supplier code', 'заявленный код', 'код поставщика', 'kodu']],
            ['Declared heading', 'declared_heading', 'string', 'dimension',
                'First 4 digits of declared_code — comparable with ai_code.',
                ['declared heading', 'заявленная позиция']],
            ['Declared group', 'declared_group', 'string', 'dimension',
                'Catalog name of declared_code, as written on the invoice.',
                ['group', 'группа', 'qrup adı']],
            ['Excise amount', 'excise_amount', 'decimal', 'metric',
                'Excise tax amount.',
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
                'Total amount of the row: the line total (a whole invoice for invoice-list rows). This is the turnover (dövriyyə / оборот) — sum it.',
                ['turnover', 'total', 'total amount', 'оборот', 'сумма', 'итого', 'dövriyyə', 'cəmi', 'yekun məbləğ']],
            ['Classified code', 'ai_code', 'string', 'dimension',
                "OUR classifier's answer for the line's item: a 4-digit XİF MN heading, or '99' for a service. NULL while not classified (or no item name).",
                ['category', 'heading', 'code', 'goods group', 'категория', 'товарная позиция', 'код', 'kateqoriya', 'kod']],
            ['Goods or service', 'ai_kind', 'string', 'dimension',
                "'good' or 'service', as classified.",
                ['goods or service', 'товар или услуга', 'mal və ya xidmət']],
            ['Category name', 'ai_heading_name', 'string', 'dimension',
                'Name of the ai_code heading (Azerbaijani).',
                ['category name', 'название категории', 'kateqoriyanın adı']],
            ['Classification status', 'ai_status', 'string', 'dimension',
                'classified | in_progress | needs_review (waiting for a human expert) | rejected; NULL for rows without an item name.',
                ['status', 'статус классификации', 'status']],
            ['Upload', 'upload_name', 'string', 'dimension',
                'File name of the upload the row came from (NULL for rows loaded before uploads were tracked).',
                ['file', 'upload', 'файл', 'загрузка', 'fayl', 'yükləmə']],
            ['Upload time', 'uploaded_at', 'datetime', 'date',
                'When that upload was made.',
                ['upload date', 'дата загрузки', 'yüklənmə tarixi']],
        ];

        foreach ($entries as [$concept, $column, $type, $role, $desc, $aliases]) {
            MetadataCatalogEntry::updateOrCreate(
                ['table_name' => $table, 'column_name' => $column, 'business_concept' => $concept],
                ['data_type' => $type, 'role' => $role, 'description' => $desc, 'aliases' => $aliases, 'is_active' => true],
            );
        }
    }
}
