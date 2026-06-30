<?php

namespace App\Services\NlSql;

class SqlGuard
{
    /** Statement-level keywords that must never appear in a read query. */
    private const FORBIDDEN = [
        'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
        'grant', 'revoke', 'merge', 'call', 'copy', 'vacuum', 'analyze',
        'reindex', 'cluster', 'comment', 'do', 'set', 'reset', 'begin',
        'commit', 'rollback', 'savepoint', 'listen', 'notify', 'lock',
        'prepare', 'execute', 'deallocate', 'refresh', 'into', 'nextval',
        'setval', 'dblink', 'pg_sleep', 'pg_read_file', 'pg_ls_dir',
        'lo_import', 'lo_export',
    ];

    /**
     * @param  array<int, string>  $allowedTables
     */
    public function __construct(
        private readonly array $allowedTables,
        private readonly int $maxRows = 1000,
    ) {}

    /**
     * Validate an LLM-generated query and return the safe SQL to execute
     * (wrapped with a hard row cap). Throws SqlGuardException on any violation.
     */
    public function sanitize(string $sql): string
    {
        $clean = trim($sql);

        if ($clean === '') {
            throw new SqlGuardException(__('Empty query.'));
        }

        // Drop a single trailing semicolon; anything more means multiple statements.
        $clean = rtrim($clean);
        $clean = preg_replace('/;\s*$/', '', $clean) ?? $clean;

        $scan = $this->stripComments($clean);

        if (str_contains($scan, ';')) {
            throw new SqlGuardException(__('Only a single statement is allowed.'));
        }

        if (! preg_match('/^\s*(select|with)\b/i', $scan)) {
            throw new SqlGuardException(__('Only SELECT/WITH queries are allowed.'));
        }

        foreach (self::FORBIDDEN as $kw) {
            if (preg_match('/\b'.preg_quote($kw, '/').'\b/i', $scan)) {
                throw new SqlGuardException(__('Disallowed keyword: :kw.', ['kw' => $kw]));
            }
        }

        $this->assertTablesAllowed($scan);

        // Hard row cap, independent of whatever the inner query does.
        return "SELECT * FROM (\n{$clean}\n) AS _q LIMIT {$this->maxRows}";
    }

    private function assertTablesAllowed(string $scan): void
    {
        preg_match_all('/\b(?:from|join)\s+("?[a-zA-Z_][a-zA-Z0-9_$.]*"?)/i', $scan, $m);
        foreach ($m[1] ?? [] as $ref) {
            $name = strtolower(trim($ref, '"'));
            if (str_contains($name, '.')) {
                $parts = explode('.', $name);
                $name = end($parts);
            }
            if (! in_array($name, $this->allowedTables, true)) {
                throw new SqlGuardException(__('Table not allowed: :name.', ['name' => $name]));
            }
        }
    }

    private function stripComments(string $sql): string
    {
        $sql = preg_replace('/--[^\n]*/', ' ', $sql) ?? $sql;       // line comments
        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;     // block comments

        return $sql;
    }
}
