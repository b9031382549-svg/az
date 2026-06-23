<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The semantic layer the LLM reads to translate natural language into SQL.
// One row per (table, column) business concept. Designed to grow: `relationships`
// holds join paths so new related tables become queryable without code changes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metadata_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('business_concept');                 // "Turnover"
            $table->string('table_name');                       // "e_invoices"
            $table->string('column_name')->nullable();          // "total_amount"
            $table->string('data_type');                        // decimal | date | string
            $table->string('role')->default('dimension');       // metric | dimension | identifier | date
            $table->text('description')->nullable();            // human/LLM-facing explanation
            $table->json('aliases')->nullable();                // synonyms (multi-language) for NL matching
            $table->json('relationships')->nullable();          // join paths to other tables (for growth)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['table_name', 'column_name', 'business_concept']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metadata_catalog');
    }
};
