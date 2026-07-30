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

        // Validate a copy with comments removed, string-literal CONTENT emptied
        // and a function's syntactic FROM/FOR blanked (EXTRACT/TRIM/SUBSTRING/
        // OVERLAY), so a blocked keyword or ';' that is really data — series =
        // 'DROP', number = 'a;b' — and a column read via EXTRACT(x FROM col)
        // cannot trip the checks. The executed $clean keeps the real query.
        $scan = $this->maskFunctionalFrom($this->neutralizeForScan($clean));

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
        // Names introduced by WITH ... AS (...) are CTEs — query-local, not real
        // tables. The prompt encourages CTEs, so exempt them; base tables read
        // inside a CTE still go through FROM/JOIN and are checked below.
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_$]*)\s+as\s*\(/i', $scan, $cte);
        $cteNames = array_map('strtolower', $cte[1] ?? []);

        foreach ($this->tableReferences($scan) as $name) {
            if (in_array($name, $cteNames, true)) {
                continue; // a reference to a CTE, not a base table
            }
            if (! in_array($name, $this->allowedTables, true)) {
                throw new SqlGuardException(__('Table not allowed: :name.', ['name' => $name]));
            }
        }
    }

    /**
     * Every base-relation name read in a table position: the item right after
     * FROM/JOIN AND each comma-separated item in a FROM list. The comma case is
     * the seam a plain "token after FROM/JOIN" scan misses — an implicit cross
     * join like "FROM e_invoices, information_schema.columns" would otherwise
     * reach the catalog unchecked (the read-only role does NOT block
     * information_schema / pg_catalog). Schema qualifier is dropped so
     * "pg_catalog.pg_user" is judged as "pg_user" against the allow-list.
     * Regex-level, not a real parser, and deliberately over-broad: a stray
     * false "not allowed" is safer than a missed system-table read.
     *
     * @return array<int, string>
     */
    private function tableReferences(string $scan): array
    {
        // Capture each FROM/JOIN target list, up to the next clause keyword.
        preg_match_all(
            '/\b(?:from|join)\b\s+(.+?)(?=\b(?:from|join|where|group|having|order|limit|offset|union|intersect|except|on|using|window)\b|$)/is',
            $scan,
            $clauses,
        );

        $names = [];
        foreach ($clauses[1] ?? [] as $list) {
            foreach ($this->splitTopLevelCommas($list) as $item) {
                if (($name = $this->relationName($item)) !== null) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Split a FROM list on top-level commas only — commas inside parentheses
     * are function args / subqueries, not table separators.
     *
     * @return array<int, string>
     */
    private function splitTopLevelCommas(string $list): array
    {
        $items = [];
        $depth = 0;
        $buf = '';
        foreach (str_split($list) as $ch) {
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            }
            if ($ch === ',' && $depth === 0) {
                $items[] = $buf;
                $buf = '';

                continue;
            }
            $buf .= $ch;
        }
        $items[] = $buf;

        return $items;
    }

    /**
     * The base-relation name of one FROM-list item (leading identifier, alias
     * and schema qualifier stripped), or null when the item is a subquery or a
     * set-returning function call rather than a base table.
     */
    private function relationName(string $item): ?string
    {
        $item = ltrim($item);

        // Subquery in table position — its own FROM/JOIN is scanned separately.
        if ($item === '' || $item[0] === '(') {
            return null;
        }

        if (! preg_match('/^"?([a-zA-Z_][a-zA-Z0-9_$.]*)"?/', $item, $m)) {
            return null;
        }
        $ident = $m[1];

        // A function call in FROM (e.g. generate_series(...)) is not a base
        // table; the keyword scan still governs which functions may run.
        if (preg_match('/^"?'.preg_quote($ident, '/').'"?\s*\(/', $item)) {
            return null;
        }

        $name = strtolower($ident);
        if (str_contains($name, '.')) {
            $parts = explode('.', $name);
            $name = end($parts);
        }

        return $name !== '' ? $name : null;
    }

    /**
     * A copy of the SQL safe to scan for keywords / statement separators /
     * tables: comments are dropped and single-quoted string literals are
     * emptied to ''. One left-to-right pass so the three contexts don't corrupt
     * each other — a '--' or ';' inside a literal stays data, and a quote inside
     * a comment is ignored (a naive strip-comments-then-strings would mangle
     * both). Only standard '...' literals are recognised ('' escapes an inner
     * quote); the model never emits E'' or dollar-quoted strings, and if it did
     * they fail safe (scanned, not skipped).
     */
    private function neutralizeForScan(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            // Line comment: -- to end of line.
            if ($ch === '-' && $next === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                $out .= ' ';

                continue;
            }

            // Block comment: /* ... */.
            if ($ch === '/' && $next === '*') {
                $i += 2;
                while ($i < $len && ! ($sql[$i] === '*' && ($sql[$i + 1] ?? '') === '/')) {
                    $i++;
                }
                $i += 2;
                $out .= ' ';

                continue;
            }

            // Single-quoted string literal ('' escapes a quote); emit an empty
            // literal so surrounding tokens don't merge.
            if ($ch === "'") {
                $i++;
                while ($i < $len) {
                    if ($sql[$i] === "'") {
                        if (($sql[$i + 1] ?? '') === "'") {
                            $i += 2; // escaped quote — still inside the string

                            continue;
                        }
                        $i++; // closing quote

                        break;
                    }
                    $i++;
                }
                $out .= "''";

                continue;
            }

            $out .= $ch;
            $i++;
        }

        return $out;
    }

    /**
     * Blank the FROM/FOR keywords that are part of a function's SYNTAX rather
     * than a table clause: EXTRACT(field FROM src), TRIM(... FROM str),
     * SUBSTRING(str FROM n FOR len), OVERLAY(... FROM n FOR len). Without this
     * the table scan reads the column after such a FROM as a table and wrongly
     * rejects a valid query ("Table not allowed: invoice_date"). A FROM/JOIN
     * that opens a real table clause is never inside one of these calls, so it
     * stays intact and is still validated (a disallowed table read inside the
     * function's own subquery argument keeps its own clause FROM and is caught).
     * Runs on the comment/string-neutralised copy; length is preserved.
     */
    private function maskFunctionalFrom(string $sql): string
    {
        $fns = ['extract', 'trim', 'substring', 'substr', 'overlay'];
        $out = '';
        $stack = [];    // enclosing call name per open paren (null = grouping/subquery)
        $word = '';     // identifier being accumulated
        $prevWord = ''; // last identifier — the callee when a '(' immediately follows

        $flush = function () use (&$word, &$out, &$stack, &$prevWord, $fns) {
            if ($word === '') {
                return;
            }
            $lw = strtolower($word);
            $top = end($stack) ?: null;
            // A FROM/FOR directly inside one of these calls is syntax, not a table.
            $out .= (($lw === 'from' || $lw === 'for') && in_array($top, $fns, true))
                ? str_repeat(' ', strlen($word))
                : $word;
            $prevWord = $lw;
            $word = '';
        };

        foreach (str_split($sql) as $ch) {
            if (preg_match('/[A-Za-z0-9_$]/', $ch)) {
                $word .= $ch;

                continue;
            }
            $flush();
            $out .= $ch;
            if ($ch === '(') {
                $stack[] = $prevWord !== '' ? $prevWord : null;
                $prevWord = '';
            } elseif ($ch === ')') {
                array_pop($stack);
                $prevWord = '';
            } elseif (! ctype_space($ch)) {
                $prevWord = ''; // an operator/comma/quote breaks the name→'(' adjacency
            }
        }
        $flush();

        return $out;
    }
}
