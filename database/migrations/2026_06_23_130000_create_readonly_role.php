<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Creates the dedicated Postgres role used to execute LLM-generated SQL.
// It is the hard security boundary for the NL->SQL feature: SELECT only, on the
// analytics tables, no access to users/sessions, read-only transactions, and a
// statement timeout. Even a maliciously crafted query cannot write or read PII.
return new class extends Migration
{
    /** Tables the read-only role is allowed to read. Grant new analytics tables here. */
    private array $tables = ['e_invoices'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // Postgres role/grants; skip on sqlite (tests).
        }

        $user = (string) config('database.connections.pgsql_ro.username');
        $pass = (string) config('database.connections.pgsql_ro.password');
        $db = (string) config('database.connections.pgsql.database');

        $ident = $this->quoteIdent($user);
        $lit = $this->quoteLiteral($pass);

        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = {$this->quoteLiteral($user)}) THEN
                    CREATE ROLE {$ident} LOGIN PASSWORD {$lit};
                ELSE
                    ALTER ROLE {$ident} LOGIN PASSWORD {$lit};
                END IF;
            END
            \$\$;
        SQL);

        // No schema-create / general privileges; just connect + read named tables.
        DB::statement("REVOKE ALL ON DATABASE {$this->quoteIdent($db)} FROM {$ident}");
        DB::statement("GRANT CONNECT ON DATABASE {$this->quoteIdent($db)} TO {$ident}");
        DB::statement("GRANT USAGE ON SCHEMA public TO {$ident}");

        foreach ($this->tables as $table) {
            DB::statement("GRANT SELECT ON TABLE {$this->quoteIdent($table)} TO {$ident}");
        }

        // Belt-and-suspenders runtime guards baked into the role.
        DB::statement("ALTER ROLE {$ident} SET default_transaction_read_only = on");
        DB::statement("ALTER ROLE {$ident} SET statement_timeout = '8000'");
        DB::statement("ALTER ROLE {$ident} SET lock_timeout = '2000'");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $user = (string) config('database.connections.pgsql_ro.username');
        $db = (string) config('database.connections.pgsql.database');
        $ident = $this->quoteIdent($user);

        foreach ($this->tables as $table) {
            DB::statement("REVOKE ALL ON TABLE {$this->quoteIdent($table)} FROM {$ident}");
        }
        DB::statement("REVOKE ALL ON SCHEMA public FROM {$ident}");
        DB::statement("REVOKE ALL ON DATABASE {$this->quoteIdent($db)} FROM {$ident}");
        DB::statement("DROP ROLE IF EXISTS {$ident}");
    }

    private function quoteIdent(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
