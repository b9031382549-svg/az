<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Trigram index for the Catalog (memory) tree search (ILIKE on answer_cache.name).
// pg_trgm is Postgres-only; skip on sqlite (tests), where the search falls back to a
// plain LIKE without an index.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX IF NOT EXISTS answer_cache_name_trgm ON answer_cache USING gin (name gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS answer_cache_name_trgm');
        }
    }
};
