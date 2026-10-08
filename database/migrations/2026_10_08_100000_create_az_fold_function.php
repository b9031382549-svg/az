<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// az_fold(text): Azerbaijani text folded so a name matches however it is typed — lower case, and
// ı / İ / I → i, ə → e, ş → s, ç → c, ğ → g, ö → o, ü → u (plus the stray combining dot a
// lower-cased İ can leave). Postgres' lower() / ILIKE turn I into i, never into ı, so
// 'QAZLI' ILIKE '%qazlı%' is false. The AI chat and the Invoices search compare names through it.
// IMMUTABLE, so it can back an index later. The chat's read-only role may call it: EXECUTE on
// a function is granted to PUBLIC by default.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // the sqlite tests search with LIKE
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION az_fold(t text) RETURNS text
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
            AS $$ SELECT lower(translate(t, 'İIıƏəŞşÇçĞğÖöÜü' || chr(775), 'iiieessccggoouu')) $$
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS az_fold(text)');
        }
    }
};
