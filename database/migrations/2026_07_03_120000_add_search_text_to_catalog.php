<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Diacritic-folded copy of name + synonyms, for diacritic-insensitive lexical
// retrieval (see App\Support\AzFold). Built by `catalog:build-search-text`; the
// original name/synonyms and the embeddings are untouched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog', function (Blueprint $table) {
            $table->text('search_text')->nullable()->after('synonyms');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX catalog_search_text_trgm ON catalog USING gin (search_text gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS catalog_search_text_trgm');
        }
        Schema::table('catalog', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};
