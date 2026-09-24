<?php

namespace Tests\Feature\NlSql;

use App\Models\ClassificationItem;
use App\Models\EInvoice;
use App\Models\ImportBatch;
use App\Models\MetadataCatalogEntry;
use App\Models\RubricatorNode;
use App\Services\NlSql\SchemaContext;
use App\Services\NlSql\SqlGuard;
use App\Services\NlSql\SqlGuardException;
use Database\Seeders\MetadataCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceLinesViewTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $resolution, ?string $code = null, bool $searched = false): ClassificationItem
    {
        $item = ClassificationItem::create([
            'batch' => 'b', 'source_text' => 'x', 'source_hash' => bin2hex(random_bytes(16)),
            'resolution' => $resolution, 'final_code' => $code, 'kind' => $code === '99' ? 'service' : ($code ? 'good' : null),
        ]);
        if ($searched) {
            $item->results()->create(['mechanism' => 'search', 'matched_code' => null, 'status' => 'needs_review']);
        }

        return $item;
    }

    private function line(?ClassificationItem $item, array $extra = []): EInvoice
    {
        return EInvoice::create($extra + [
            'item_name' => $item ? 'JBL BAR 2.1' : null,
            'total_amount' => 100,
            'classification_item_id' => $item?->id,
        ]);
    }

    /** @return array<string, mixed> the view row of an e_invoices line */
    private function viewRow(EInvoice $line): array
    {
        return (array) DB::table('invoice_lines')->where('total_amount', $line->total_amount)->first();
    }

    public function test_a_line_carries_its_classification_its_declared_code_and_its_upload(): void
    {
        RubricatorNode::create(['level' => 2, 'code' => '8518', 'title' => 'Mikrofonlar və reproduktorlar', 'kind' => 'good']);
        $batch = (string) Str::uuid();
        ImportBatch::create(['key' => $batch, 'label' => 'Şablon.xlsx', 'source' => 'invoices', 'format' => 'sablon']);
        $line = $this->line($this->item('agreed', '8518'), [
            'declared_code' => '3305200000', 'unit' => 'ƏDƏD', 'import_batch' => $batch, 'invoice_key' => 'MT|1',
        ]);

        $row = $this->viewRow($line);

        $this->assertSame('8518', $row['ai_code']);
        $this->assertSame('good', $row['ai_kind']);
        $this->assertSame('classified', $row['ai_status']);
        $this->assertSame('Mikrofonlar və reproduktorlar', $row['ai_heading_name']);
        $this->assertSame('3305200000', $row['declared_code']);
        $this->assertSame('3305', $row['declared_heading']);
        $this->assertSame('Şablon.xlsx', $row['upload_name']);
        $this->assertSame('MT|1', $row['invoice_key']);
    }

    public function test_the_status_follows_the_classification_pipeline(): void
    {
        config()->set('classify.search_resolver.enabled', true);
        $cases = [
            'in_progress' => [$this->item('pending'), $this->item('conflict')],      // conflict still under web search
            'needs_review' => [$this->item('conflict', null, true), $this->item('no_match')],
            'rejected' => [$this->item('rejected')],
            'trash' => [$this->item('trash')],                                       // names no product
            'classified' => [$this->item('confirmed', '99'), $this->item('ai_resolved', '8471')],
        ];

        $total = 1;
        foreach ($cases as $expected => $items) {
            foreach ($items as $item) {
                $line = $this->line($item, ['total_amount' => $total++]);
                $this->assertSame($expected, $this->viewRow($line)['ai_status'], "item {$item->resolution}");
            }
        }

        // A legacy invoice-list row (no item) has no classification at all — but its money is there.
        $legacy = $this->line(null, ['total_amount' => 999, 'series' => 'MT', 'number' => '9', 'invoice_key' => 'MT|9']);
        $row = $this->viewRow($legacy);
        $this->assertNull($row['ai_status']);
        $this->assertNull($row['ai_code']);
        $this->assertSame('MT|9', $row['invoice_key']);
    }

    public function test_the_chat_sees_only_the_view_with_its_business_descriptions(): void
    {
        $this->seed(MetadataCatalogSeeder::class);

        $schema = app(SchemaContext::class);
        $this->assertSame(['invoice_lines'], $schema->allowedTables());

        // (describe() reads information_schema — Postgres-only; its enrichment comes from these rows.)
        $described = MetadataCatalogEntry::where('table_name', 'invoice_lines')->pluck('description', 'column_name');
        $this->assertStringContainsString('COUNT(DISTINCT invoice_key)', $described['invoice_key']);
        $this->assertStringContainsString("'99' for a service", $described['ai_code']);
        $this->assertStringContainsString('SUPPLIER', $described['declared_code']);
        // Every described column really exists in the view.
        $columns = collect(DB::select('PRAGMA table_info(invoice_lines)'))->pluck('name')->all();
        $this->assertSame([], array_values(array_diff($described->keys()->all(), $columns)));

        $guard = new SqlGuard($schema->allowedTables());
        $guard->sanitize('SELECT ai_code, sum(total_amount) AS turnover FROM invoice_lines GROUP BY ai_code');

        $this->expectException(SqlGuardException::class);
        $guard->sanitize('SELECT * FROM classification_items');
    }
}
