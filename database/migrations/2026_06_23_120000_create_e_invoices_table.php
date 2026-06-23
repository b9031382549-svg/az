<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors the columns of FoodWholesale_sampleData.xlsx (Task 1 sample data).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('row_no')->nullable();        // "No."
            $table->string('supplier_tin')->index();              // Supplier TIN (issuer)
            $table->string('recipient_tin')->index();             // Recipient TIN (buyer)
            $table->date('invoice_date')->index();                // e-Invoice Date
            $table->date('approval_date')->nullable()->index();   // e-Invoice Approval Date
            $table->string('series')->nullable();                 // e-Invoice Series
            $table->string('number')->nullable();                 // e-Invoice Number
            $table->decimal('excise_amount', 18, 2)->default(0);
            $table->decimal('vat_taxable_amount', 18, 2)->default(0);
            $table->decimal('non_vat_taxable_amount', 18, 2)->default(0);
            $table->decimal('vat_exempt_amount', 18, 2)->default(0);
            $table->decimal('zero_rated_vat_amount', 18, 2)->default(0);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->decimal('road_tax', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0)->index(); // Turnover
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_invoices');
    }
};
