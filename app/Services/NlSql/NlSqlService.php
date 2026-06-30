<?php

namespace App\Services\NlSql;

use App\Services\Llm\OpenRouterClient;
use App\Support\Audit;
use App\Support\LlmLog;
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
     * @return array{question: string, sql: ?string, answer: ?string, explanation: ?string, columns: array<int,string>, rows: array<int,array<string,mixed>>, error: ?string}
     */
    public function ask(string $question): array
    {
        $result = [
            'question' => $question,
            'sql' => null,
            'answer' => null,
            'explanation' => null,
            'columns' => [],
            'rows' => [],
            'error' => null,
        ];

        try {
            $generated = $this->generate($question);
            $result['sql'] = $generated['sql'];
            $result['answer'] = $generated['answer'];
            $result['explanation'] = $generated['explanation'];

            // Conversational reply (e.g. "what is today's date?") — nothing to run.
            if ($generated['sql'] === null) {
                return $result;
            }

            $guard = new SqlGuard($this->schema->allowedTables());
            $safeSql = $guard->sanitize($generated['sql']);

            $rows = DB::connection('pgsql_ro')->select($safeSql);
            $rows = array_map(fn ($r) => (array) $r, $rows);

            $result['rows'] = $rows;
            $result['columns'] = $rows ? array_keys($rows[0]) : [];
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        Audit::log('nlsql.query', [
            'question' => $question,
            'sql' => $result['sql'],
            'conversational' => $result['answer'] !== null,
            'rows' => count($result['rows']),
            'error' => $result['error'],
        ]);

        return $result;
    }

    /**
     * @return array{sql: ?string, answer: ?string, explanation: ?string}
     */
    private function generate(string $question): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $question],
        ];

        try {
            $response = $this->llm->jsonWithUsage($messages);
        } catch (Throwable $e) {
            LlmLog::record('nlsql', (string) config('services.openrouter.model'), [], 0, 'error', null, $messages, null, $e->getMessage());
            throw $e;
        }

        LlmLog::record(
            'nlsql', $response['model'], $response['usage'], $response['latency_ms'] ?? 0,
            'ok', $response['raw'] ?? null, $messages,
        );

        $json = $response['data'];

        $sql = $json['sql'] ?? null;
        $sql = is_string($sql) && trim($sql) !== '' ? trim($sql) : null;

        $answer = $json['answer'] ?? null;
        $answer = is_string($answer) && trim($answer) !== '' ? trim($answer) : null;

        // Either a query to run, or a direct conversational answer — but not nothing.
        if ($sql === null && $answer === null) {
            throw new SqlGuardException(__('The model did not return any SQL or answer.'));
        }

        return ['sql' => $sql, 'answer' => $answer, 'explanation' => $json['explanation'] ?? null];
    }

    private function systemPrompt(): string
    {
        $schema = $this->schema->describe();
        $context = $this->dataContext();

        return <<<PROMPT
        You are a senior data analyst that writes PostgreSQL queries over an
        e-invoice (electronic invoice) database.

        CONTEXT:
        {$context}

        DATABASE SCHEMA (only these tables and columns exist):
        {$schema}

        RULES:
        - If the question needs the invoice data, output exactly one read-only SQL
          SELECT statement (a WITH/CTE is fine) in "sql". Never write INSERT,
          UPDATE, DELETE or any DDL.
        - If the question does NOT need the data — small talk, what you can do, or
          the current date/time — set "sql" to null and put a short, direct reply
          in "answer" (for date questions use the current date from CONTEXT).
        - Use only the tables and columns listed above. Do not invent columns.
        - "total_amount" is the turnover. VAT is "vat_amount".
        - Dates are SQL DATE values. The current real-world date is given in
          CONTEXT above — use it when the user refers to "today" / "now" / a
          specific calendar date, and when answering conversationally about dates.
        - The invoice data is a historical snapshot whose coverage is given in
          CONTEXT. For an OPEN relative period ("last N days", "recent", "this
          month") with no explicit calendar year, measure it from the latest
          available date in the data, since the snapshot ends there, e.g.
          invoice_date > (SELECT max(invoice_date) FROM e_invoices) - INTERVAL 'N days'.
          Mention in the explanation that the window is relative to the latest
          data date.
        - If the user asks about a real calendar period the data does not cover
          (e.g. the actual current date), it is correct to return an empty result;
          do not silently shift it onto unrelated old data.
        - Always give aggregate columns clear aliases (e.g. SELECT sum(total_amount) AS turnover).
        - Order results sensibly and keep them reasonably small.
        - TIN values are strings (e.g. 'A_00000001').

        Respond with a strict JSON object only:
        {"sql": "<the SQL, or null when no query is needed>", "answer": "<a direct reply when no query is needed, else null>", "explanation": "<one sentence describing what the SQL returns>"}
        PROMPT;
    }

    /**
     * Real-world clock + the actual date span of the loaded data, injected into
     * the prompt so the model knows "today" (it otherwise defaults to its
     * training cutoff) and can reconcile it with the historical snapshot.
     */
    private function dataContext(): string
    {
        $now = now();
        $lines = [
            '- The current real-world date is '.$now->format('l, j F Y')
                .' ('.$now->format('Y-m-d H:i').', timezone '.config('app.timezone').').',
        ];

        try {
            $span = DB::connection('pgsql_ro')->selectOne(
                'SELECT min(invoice_date) AS min_d, max(invoice_date) AS max_d, count(*) AS n FROM e_invoices'
            );
            if ($span && $span->n > 0) {
                $lines[] = '- The invoice data is historical: it covers '.$span->min_d.' … '.$span->max_d
                    .' ('.number_format((int) $span->n).' invoices). There is no data for the current date.';
            } else {
                $lines[] = '- The invoice table is currently empty.';
            }
        } catch (Throwable) {
            // Coverage is best-effort; current date alone is still useful.
        }

        return implode("\n", $lines);
    }
}
