<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Everyday synonyms / keywords for each code (the colloquial terms invoices use),
// generated offline. Used by both lexical retrieval and the embedding text.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog', function (Blueprint $table) {
            $table->text('synonyms')->nullable();
        });

        // Trigram index so synonyms participate in fast ILIKE / similarity search.
        // Postgres-only; skip on sqlite (tests).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX catalog_synonyms_trgm ON catalog USING gin (synonyms gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        Schema::table('catalog', function (Blueprint $table) {
            $table->dropColumn('synonyms');
        });
    }
};
