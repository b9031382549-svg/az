<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NlSqlGrant extends Command
{
    protected $signature = 'nlsql:grant';

    protected $description = 'Sync the read-only role so it can SELECT ONLY the nlsql.tables allow-list (system tables stay inaccessible)';

    public function handle(): int
    {
        $role = (string) config('database.connections.pgsql_ro.username');
        $tables = array_values((array) config('nlsql.tables', []));
        $ident = '"'.str_replace('"', '""', $role).'"';

        // Strip every table privilege, then grant SELECT only on the allow-list.
        DB::statement("REVOKE SELECT ON ALL TABLES IN SCHEMA public FROM {$ident}");

        foreach ($tables as $table) {
            $t = '"'.str_replace('"', '""', $table).'"';
            DB::statement("GRANT SELECT ON TABLE {$t} TO {$ident}");
            $this->line("  granted SELECT on {$table}");
        }

        $this->info("Read-only role '{$role}' may now read only: ".implode(', ', $tables));

        return self::SUCCESS;
    }
}
