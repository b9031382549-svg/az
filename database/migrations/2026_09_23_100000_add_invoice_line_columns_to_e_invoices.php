<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The line-level e-invoice export ("Şablon") lands in e_invoices too: one row = one invoice
// LINE, the invoice's own fields repeated on every line. A legacy 15-column row stays what it
// was — an invoice with a single line and no item detail — so every existing reader keeps
// working. The new export does not guarantee the TINs or even the date, hence nullable.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('e_invoices', function (Blueprint $table) {
            $table->string('supplier_tin')->nullable()->change();
            $table->string('recipient_tin')->nullable()->change();
            $table->date('invoice_date')->nullable()->change();

            // The upload that brought the row in (import_batches.key); null for rows
            // imported before uploads were tracked.
            $table->string('import_batch', 36)->nullable()->index();
            // series|number — the invoice's identity. Null = the line cannot be tied to
            // a specific invoice (the export left series or number empty).
            $table->string('invoice_key')->nullable()->index();
            $table->string('invoice_type')->nullable();
            $table->string('supplier_tax_office')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('recipient_tax_office')->nullable();
            $table->string('recipient_name')->nullable();

            // The line itself. declared_* is what the SUPPLIER put on the invoice — kept
            // for later use, never shown to the classifier and never treated as the truth.
            $table->text('item_name')->nullable();
            $table->string('declared_code', 20)->nullable()->index();
            $table->text('declared_group')->nullable();
            $table->string('unit', 64)->nullable();
            $table->decimal('quantity', 18, 4)->nullable();

            // The classification of this line's item name (one item per unique name per
            // upload). nullOnDelete: deleting a run in Review keeps the invoice lines.
            $table->foreignId('classification_item_id')->nullable()
                ->constrained('classification_items')->nullOnDelete();
            // Postgres does not index a foreign key by itself; the hint lookups and the
            // SET NULL on item deletes both filter by it.
            $table->index('classification_item_id');
        });

        // Legacy rows get the same identity the legacy importer dedups on.
        DB::table('e_invoices')
            ->whereNotNull('series')->where('series', '!=', '')
            ->whereNotNull('number')->where('number', '!=', '')
            ->update(['invoice_key' => DB::raw('"series" || \'|\' || "number"')]);
    }

    public function down(): void
    {
        Schema::table('e_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('classification_item_id');
            $table->dropIndex(['import_batch']);
            $table->dropIndex(['invoice_key']);
            $table->dropIndex(['declared_code']);
            $table->dropColumn([
                'import_batch', 'invoice_key', 'invoice_type', 'supplier_tax_office', 'supplier_name',
                'recipient_tax_office', 'recipient_name', 'item_name', 'declared_code',
                'declared_group', 'unit', 'quantity',
            ]);
        });
    }
};
