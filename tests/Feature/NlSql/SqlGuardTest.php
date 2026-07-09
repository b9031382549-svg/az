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
}
