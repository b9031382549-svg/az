<?php

namespace App\Services\NlSql;

use App\Services\Llm\OpenRouterClient;
use Illuminate\Support\Facades\DB;
use Throwable;

class NlSqlService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly SchemaContext $schema,
    ) {}

    /**
     * Translate a natural-language question into SQL, execute it read-only and
     * return the result set together with the SQL and a short explanation.
     *
     * @return array{question: string, sql: ?string, explanation: ?string, columns: array<int,string>, rows: array<int,array<string,mixed>>, error: ?string}
     */
    public function ask(string $question): array
    {
        $result = [
            'question' => $question,
            'sql' => null,
            'explanation' => null,
            'columns' => [],
            'rows' => [],
            'error' => null,
        ];

        try {
            $generated = $this->generate($question);
            $result['sql'] = $generated['sql'];
            $result['explanation'] = $generated['explanation'];

            $guard = new SqlGuard($this->schema->allowedTables());
            $safeSql = $guard->sanitize($generated['sql']);

            $rows = DB::connection('pgsql_ro')->select($safeSql);
            $rows = array_map(fn ($r) => (array) $r, $rows);

            $result['rows'] = $rows;
            $result['columns'] = $rows ? array_keys($rows[0]) : [];
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * @return array{sql: string, explanation: ?string}
     */
    private function generate(string $question): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $question],
        ];

        $json = $this->llm->json($messages);

        $sql = $json['sql'] ?? null;
        if (! is_string($sql) || trim($sql) === '') {
            throw new SqlGuardException('The model did not return any SQL.');
        }

        return ['sql' => $sql, 'explanation' => $json['explanation'] ?? null];
    }

    private function systemPrompt(): string
    {
        $schema = $this->schema->describe();

        return <<<PROMPT
        You are a senior data analyst that writes PostgreSQL queries over an
        e-invoice (electronic invoice) database.

        DATABASE SCHEMA (only these tables and columns exist):
        {$schema}

        RULES:
        - Output exactly one read-only SQL SELECT statement (a WITH/CTE is fine).
          Never write INSERT, UPDATE, DELETE or any DDL.
        - Use only the tables and columns listed above. Do not invent columns.
        - "total_amount" is the turnover. VAT is "vat_amount".
        - Dates are SQL DATE values. The data is historical. When the user asks
          for a relative period such as "last N days" / "recent", measure it from
          the latest available date in the data, e.g.
          invoice_date > (SELECT max(invoice_date) FROM e_invoices) - INTERVAL 'N days',
          not from the current wall-clock date.
        - Always give aggregate columns clear aliases (e.g. SELECT sum(total_amount) AS turnover).
        - Order results sensibly and keep them reasonably small.
        - TIN values are strings (e.g. 'A_00000001').

        Respond with a strict JSON object only:
        {"sql": "<the SQL>", "explanation": "<one sentence describing what it returns>"}
        PROMPT;
    }
}
