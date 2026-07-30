<?php

namespace Tests\Feature\NlSql;

use App\Services\NlSql\SqlGuard;
use App\Services\NlSql\SqlGuardException;
use Tests\TestCase;

class SqlGuardTest extends TestCase
{
    private function guard(): SqlGuard
    {
        return new SqlGuard(['e_invoices']);
    }

    public function test_named_cte_referencing_allowed_tables_is_accepted(): void
    {
        // The shape the /ask chat produces for a follow-up once it has context
        // (cf. the "Table not allowed: max_invoices" failure this fixes).
        $sql = 'WITH top_day AS ('
            .' SELECT invoice_date, count(*) AS n FROM e_invoices'
            .' GROUP BY invoice_date ORDER BY n DESC LIMIT 1'
            .' ) SELECT e.* FROM e_invoices e JOIN top_day t ON e.invoice_date = t.invoice_date';

        $safe = $this->guard()->sanitize($sql);

        $this->assertStringContainsString('top_day', $safe);    // CTE name not rejected
        $this->assertStringContainsString('LIMIT 1000', $safe); // still hard-capped
    }

    public function test_cte_reading_a_disallowed_base_table_is_still_rejected(): void
    {
        // Exempting the CTE alias must not let a real disallowed table slip
        // through when it is read INSIDE the CTE.
        $this->expectException(SqlGuardException::class);
        $this->expectExceptionMessage('users');

        $this->guard()->sanitize('WITH x AS (SELECT * FROM users) SELECT * FROM x');
    }

    public function test_a_plain_disallowed_table_is_still_rejected(): void
    {
        $this->expectException(SqlGuardException::class);
        $this->expectExceptionMessage('users');

        $this->guard()->sanitize('SELECT * FROM users');
    }

    public function test_comma_join_to_a_system_catalog_is_rejected(): void
    {
        // A plain "table after FROM/JOIN" scan only sees e_invoices and lets the
        // second, comma-separated relation through — an implicit cross join that
        // reaches information_schema/pg_catalog (which the read-only role does
        // NOT block). Every relation in the FROM list must be allow-listed.
        $this->expectException(SqlGuardException::class);
        $this->expectExceptionMessage('columns');

        $this->guard()->sanitize(
            'SELECT e.number, c.table_name FROM e_invoices e, information_schema.columns c'
        );
    }

    public function test_comma_join_to_pg_catalog_is_rejected(): void
    {
        $this->expectException(SqlGuardException::class);
        $this->expectExceptionMessage('pg_user');

        $this->guard()->sanitize('SELECT * FROM e_invoices, pg_catalog.pg_user');
    }

    public function test_a_legitimate_self_comma_join_is_accepted(): void
    {
        // Commas in the FROM list are fine as long as every relation is allowed;
        // only out-of-allow-list references are rejected.
        $safe = $this->guard()->sanitize(
            'SELECT a.number, b.number FROM e_invoices a, e_invoices b WHERE a.total_amount = b.total_amount'
        );

        $this->assertStringContainsString('LIMIT 1000', $safe);
    }

    public function test_commas_in_select_list_and_function_args_are_not_treated_as_tables(): void
    {
        // The FROM-list comma handling must not misread SELECT-list commas or
        // commas inside function calls as extra tables.
        $safe = $this->guard()->sanitize(
            "SELECT to_char(invoice_date, 'YYYY-MM') AS m, count(*) AS n, sum(total_amount) AS t"
            .' FROM e_invoices GROUP BY 1 ORDER BY 1'
        );

        $this->assertStringContainsString('LIMIT 1000', $safe);
    }

    public function test_a_blocked_keyword_inside_a_string_literal_is_allowed(): void
    {
        // 'DROP' is DATA here, not a statement — the keyword scan must not fire
        // on literal content (the value column may legitimately hold such text).
        $safe = $this->guard()->sanitize("SELECT * FROM e_invoices WHERE series = 'DROP'");

        $this->assertStringContainsString('LIMIT 1000', $safe);
    }

    public function test_a_semicolon_inside_a_string_literal_is_allowed(): void
    {
        $safe = $this->guard()->sanitize("SELECT * FROM e_invoices WHERE number = 'a;b'");

        $this->assertStringContainsString('LIMIT 1000', $safe);
    }

    public function test_an_escaped_quote_inside_a_literal_does_not_break_scanning(): void
    {
        $safe = $this->guard()->sanitize("SELECT * FROM e_invoices WHERE series = 'O''Brien'");

        $this->assertStringContainsString('LIMIT 1000', $safe);
    }

    public function test_a_real_second_statement_is_still_rejected(): void
    {
        // Emptying literals must not weaken multi-statement detection: a ';'
        // OUTSIDE a literal is still a hard reject.
        $this->expectException(SqlGuardException::class);
        $this->expectExceptionMessage('single statement');

        $this->guard()->sanitize("SELECT * FROM e_invoices WHERE series = 'a'; DROP TABLE e_invoices");
    }

    public function test_extract_from_a_column_is_not_treated_as_a_table(): void
    {
        // EXTRACT(field FROM col) uses FROM as function syntax, not a table
        // clause; the column must not be read as a table name.
        $safe = $this->guard()->sanitize(
            'SELECT EXTRACT(YEAR FROM invoice_date) AS y, count(*) FROM e_invoices GROUP BY y'
        );

        $this->assertStringContainsString('LIMIT 1000', $safe);
    }

    public function test_trim_and_substring_from_a_column_are_allowed(): void
    {
        $this->guard()->sanitize("SELECT TRIM(LEADING '0' FROM number) AS n FROM e_invoices");
        $safe = $this->guard()->sanitize('SELECT SUBSTRING(number FROM 1 FOR 4) AS pref FROM e_invoices');

        $this->assertStringContainsString('LIMIT 1000', $safe);
    }

    public function test_a_disallowed_table_inside_an_extract_subquery_is_still_rejected(): void
    {
        // Masking the function's own FROM must not hide a real table read in the
        // function's subquery argument — its clause FROM is still validated.
        $this->expectException(SqlGuardException::class);
        $this->expectExceptionMessage('users');

        $this->guard()->sanitize(
            'SELECT EXTRACT(YEAR FROM (SELECT max(x) FROM users)) AS y FROM e_invoices'
        );
    }
}
