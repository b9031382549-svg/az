<?php

namespace App\Services\NlSql;

use App\Services\Llm\OpenRouterClient;
use App\Support\Audit;
use App\Support\LlmLog;
use Illuminate\Support\Facades\DB;
use Throwable;

class NlSqlService
{
    /**
     * The selected taxpayer in the SQL the model writes — the server puts the VÖEN in its place,
     * so the number never reaches the external model and cannot be mistyped by it. Not the
     * "::tin" of a cast, not part of a longer name.
     */
    private const TIN_PLACEHOLDER = '/(?<![:\w]):tin\b/';

    /** A VÖEN as the data holds it — 10 digits, or the demo data's "A_00000001". */
    public const TIN_FORMAT = '/^[A-Za-z0-9_]{1,32}$/';

    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly SchemaContext $schema,
    ) {}

    /**
     * Translate a natural-language question into SQL, execute it read-only and
     * return the result set together with the SQL and a short explanation.
     *
     * With $tin the conversation is about that one taxpayer ("this taxpayer" mode): the model
     * writes the placeholder :tin, the server binds the VÖEN, and a query without it is not run.
     *
     * @param  array<int, array<string, mixed>>  $history  Prior turns (oldest→newest),
     *                                                     each carrying 'q'/'sql'/'answer'/'explanation', so a follow-up like
     *                                                     "from the previous query" can build on earlier requests.
     * @return array{question: string, sql: ?string, answer: ?string, explanation: ?string, columns: array<int,string>, rows: array<int,array<string,mixed>>, error: ?string}
     */
    public function ask(string $question, array $history = [], ?string $tin = null): array
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
            if ($tin !== null && ! preg_match(self::TIN_FORMAT, $tin)) {
                throw new SqlGuardException(__('No lines with this VÖEN in the loaded data.'));
            }

            $generated = $this->generate($question, $history, $tin);
            $result['sql'] = $generated['sql'];
            $result['answer'] = $generated['answer'];
            $result['explanation'] = $generated['explanation'];

            // Conversational reply (e.g. "what is today's date?") — nothing to run.
            if ($generated['sql'] === null) {
                return $result;
            }

            $sql = $generated['sql'];
            if ($tin !== null) {
                if (! preg_match(self::TIN_PLACEHOLDER, $sql)) {
                    throw new SqlGuardException(__('The query was not limited to the selected taxpayer — please rephrase the question.'));
                }
                // Quoted here, on our side; the guard then checks the query as it will run.
                $sql = (string) preg_replace(self::TIN_PLACEHOLDER, DB::connection('pgsql_ro')->getPdo()->quote($tin), $sql);
            }

            $guard = new SqlGuard($this->schema->allowedTables());
            $safeSql = $guard->sanitize($sql);

            $rows = DB::connection('pgsql_ro')->select($safeSql);
            $rows = array_map(fn ($r) => (array) $r, $rows);

            $result['rows'] = $rows;
            $result['columns'] = $rows ? array_keys($rows[0]) : [];
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        Audit::log('nlsql.query', [
            'question' => $question,
            'context_tin' => $tin,
            'sql' => $result['sql'],
            'conversational' => $result['answer'] !== null,
            'rows' => count($result['rows']),
            'error' => $result['error'],
        ]);

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $history
     * @return array{sql: ?string, answer: ?string, explanation: ?string}
     */
    private function generate(string $question, array $history, ?string $tin = null): array
    {
        $messages = $this->buildMessages($question, $history, $tin);
        $model = (string) config('nlsql.model');

        try {
            // touch: false — a question uses the GPU server while classification keeps it up,
            // but must not keep a paid GPU running on its own.
            $response = $this->llm->jsonWithUsage($messages, ['model' => $model, 'touch' => false]);
        } catch (Throwable $e) {
            LlmLog::record('nlsql', $model, [], 0, 'error', null, $messages, null, $e->getMessage());
            throw $e;
        }

        LlmLog::record(
            'nlsql', $response['model'], $response['usage'], $response['latency_ms'] ?? 0,
            'ok', $response['raw'] ?? null, $messages,
        );

        $json = $response['data'];

        $sql = $json['sql'] ?? null;
        $sql = is_string($sql) && trim($sql) !== '' ? trim($sql) : null;
        // Now and then the model writes its line breaks as the two characters "\n" (escaped once
        // too often in its JSON) — Postgres reads that backslash as a syntax error.
        if ($sql !== null) {
            $sql = str_replace(['\r\n', '\n', '\r', '\t'], ["\n", "\n", "\n", ' '], $sql);
        }

        $answer = $json['answer'] ?? null;
        $answer = is_string($answer) && trim($answer) !== '' ? trim($answer) : null;

        // Either a query to run, or a direct conversational answer — but not nothing.
        if ($sql === null && $answer === null) {
            throw new SqlGuardException(__('The model did not return any SQL or answer.'));
        }

        return ['sql' => $sql, 'answer' => $answer, 'explanation' => $json['explanation'] ?? null];
    }

    /**
     * Build the OpenRouter message list: the system prompt, then the recent
     * conversation turns (so follow-ups resolve), then the current question.
     *
     * @param  array<int, array<string, mixed>>  $history
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(string $question, array $history, ?string $tin = null): array
    {
        $messages = [['role' => 'system', 'content' => $this->systemPrompt($tin)]];

        foreach ($history as $turn) {
            $q = trim((string) ($turn['q'] ?? $turn['question'] ?? ''));
            $assistant = $this->replayAssistant($turn);

            // Skip turns with no question or nothing to build on (e.g. a past
            // error, or SQL today's guard rejects), so every user turn keeps its
            // matching assistant reply.
            if ($q === '' || $assistant === null) {
                continue;
            }

            $messages[] = ['role' => 'user', 'content' => $q];
            $messages[] = ['role' => 'assistant', 'content' => $assistant];
        }

        $messages[] = ['role' => 'user', 'content' => $question];

        return $messages;
    }

    /**
     * Re-create what the assistant answered on a past turn, in the same JSON
     * shape it must reply with, so the model can reference or extend its own
     * prior SQL. Null when the turn carried neither SQL nor an answer, or its
     * SQL fails today's guard.
     *
     * @param  array<string, mixed>  $turn
     */
    private function replayAssistant(array $turn): ?string
    {
        $pick = fn (string $key): ?string => isset($turn[$key]) && trim((string) $turn[$key]) !== ''
            ? trim((string) $turn[$key])
            : null;

        $fields = array_filter([
            'sql' => $pick('sql'),
            'answer' => $pick('answer'),
            'explanation' => $pick('explanation'),
        ], fn ($v) => $v !== null);

        if (! isset($fields['sql']) && ! isset($fields['answer'])) {
            return null;
        }

        // SQL written before a schema change (FROM e_invoices, before the chat moved to the
        // invoice_lines view) is an example the model copies — every new query then fails the
        // guard. Failed turns never enter the context, so such stale turns would stay "the
        // latest" for good: drop them here.
        if (isset($fields['sql']) && ! $this->passesGuard($fields['sql'])) {
            return null;
        }

        return json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function passesGuard(string $sql): bool
    {
        try {
            (new SqlGuard($this->schema->allowedTables()))->sanitize($sql);
        } catch (SqlGuardException) {
            return false;
        }

        return true;
    }

    private function systemPrompt(?string $tin = null): string
    {
        $schema = $this->schema->describe();
        $context = $this->dataContext($tin);
        $taxpayer = $tin === null ? '' : <<<'TAXPAYER'

        THIS TAXPAYER:
        The user is asking about ONE taxpayer. In SQL name its VÖEN (TIN) only by the
        placeholder :tin — unquoted, exactly so; the server puts the real VÖEN in its
        place. Never write a VÖEN number for it yourself. Every query must be limited
        to this taxpayer: supplier_tin = :tin for what it sells or supplies,
        recipient_tin = :tin for what it buys or receives, and
        (supplier_tin = :tin OR recipient_tin = :tin) when the question covers both or
        does not say. "It", "he", "she", "this company", "this taxpayer" mean it; its
        counterparties are the other side of its lines.

        TAXPAYER;

        return <<<PROMPT
        You are a senior data analyst that writes PostgreSQL queries over an
        e-invoice (electronic invoice) database.

        CONTEXT:
        {$context}
        {$taxpayer}
        DATABASE SCHEMA (only these tables and columns exist):
        {$schema}

        RULES:
        - If the question needs the invoice data, output exactly one read-only SQL
          SELECT statement (a WITH/CTE is fine) in "sql". Never write INSERT,
          UPDATE, DELETE or any DDL.
        - If the question does NOT need the data — small talk, what you can do, or
          the current date/time — set "sql" to null and put a short, direct reply
          in "answer" (for date questions use the current date from CONTEXT).
        - If the columns below can answer the question, write the query: never reply
          that you cannot calculate it, and do not ask the user to spell out names or
          codes — search for them as described below.
        - A question about WHAT is loaded — which invoices, files or uploads, how
          many, for which period — DOES need the data: query it, never reply that
          you have no information. upload_name / uploaded_at tell which upload a
          line came from (e.g. list the uploads with their line and invoice counts).
        - Use only the tables and columns listed above. Do not invent columns.
        - One row of invoice_lines is one invoice LINE (a goods/service position).
          Rows of the older invoice-list format are whole invoices stored as a
          single line with item_name NULL.
        - "total_amount" is the turnover — sum it over rows. VAT is "vat_amount".
        - An invoice is identified by "invoice_key" (series|number): count invoices
          with COUNT(DISTINCT invoice_key) — never COUNT(*) or SUM(1), which count
          lines. Lines with invoice_key NULL cannot be tied to a specific invoice —
          when counting invoices, report them separately instead of dropping them
          silently.
        - One invoice has several lines. For a per-invoice measure (average, largest
          or smallest invoice amount, lines per invoice, invoices with no VAT,
          approval delay) first aggregate the lines per invoice_key in a subquery,
          then aggregate those, e.g. SELECT avg(t) FROM (SELECT invoice_key,
          sum(total_amount) AS t FROM invoice_lines WHERE invoice_key IS NOT NULL
          GROUP BY invoice_key) x. Averaging the lines themselves is wrong.
        - VAT: the taxable base is "vat_taxable_amount", so the effective VAT rate is
          sum(vat_amount) / sum(vat_taxable_amount). VAT-exempt sales are
          "vat_exempt_amount", zero-rated ones "zero_rated_vat_amount", excise is
          "excise_amount".
        - Goods/service categories come from OUR classifier: "ai_code" (a 4-digit
          heading, '99' = a service), "ai_heading_name", "ai_kind" and
          "ai_status" (classified | in_progress = still being classified |
          needs_review = waiting for a person | rejected | trash — the item name
          names no product, e.g. only a contract reference or a date, so it is
          never classified).
          "declared_code" / "declared_heading" / "declared_group" are what the
          SUPPLIER wrote on the invoice — unverified; use them only when the
          question is about the declared codes. declared_code is the full code (up
          to 10 digits) and ai_code only 4, so compare declared_heading with
          ai_code; a declared code starting with '99' matches our service code '99'.
        - Filter a column on a literal value only when the question gives that
          value or it is listed here (ai_status, ai_kind). Never guess the values of
          invoice_type or other free-text columns.
        - Item, company, upload and unit names are Azerbaijani, typed in any case,
          with or without the Azerbaijani letters, and usually only in part (no
          quotes, no MMC / ASC, one or two words): QAZLI İÇKİ, qazlı içki and qazli
          icki are one name. To find a name from the question in item_name,
          supplier_name, recipient_name, upload_name, unit or any other text, match
          a PART of it with both sides through az_fold() — it lower-cases and turns
          ı/İ/I→i, ə→e, ş→s, ç→c, ğ→g, ö→o, ü→u:
          az_fold(item_name) LIKE '%' || az_fold('qazlı içki') || '%',
          az_fold(supplier_name) LIKE '%' || az_fold('şəki qida') || '%'.
          Never use ILIKE or lower() for this, and never compare a name with = —
          they find nothing for such spellings.
        - The user may name goods in Russian or English while item names are
          Azerbaijani. Never search item_name for the word as typed: a whole kind of
          goods (beer, cheese, cigarettes, medicines …) is found by its 4-digit
          heading in ai_code (beer = '2203') or by the Azerbaijani word in
          az_fold(ai_heading_name); a specific item by the Azerbaijani word in
          item_name (пиво → pivə, сыр → pendir, бензин → benzin).
        - Rows with item_name NULL are whole invoices, not an item — leave them out
          of item lists and rankings.
        - Dates are SQL DATE values. The current real-world date is given in
          CONTEXT above — use it when the user refers to "today" / "now" / a
          specific calendar date, and when answering conversationally about dates.
        - A month, quarter or season named without a year means the LATEST such
          period the data covers (see CONTEXT): if the data ended in 2025-05, "May"
          would be 2025-05 and "August" 2024-08.
        - Group by month with date_trunc('month', invoice_date), which keeps the
          year — never EXTRACT(MONTH …) alone, which merges different years.
        - The invoice data is a historical snapshot whose coverage is given in
          CONTEXT. For an OPEN relative period ("last N days", "recent", "this
          month") with no explicit calendar year, measure it from the latest
          available date in the data, since the snapshot ends there, e.g.
          invoice_date > (SELECT max(invoice_date) FROM invoice_lines) - INTERVAL 'N days'.
          Mention in the explanation that the window is relative to the latest
          data date.
        - If the user asks about a real calendar period the data does not cover
          (e.g. the actual current date), it is correct to return an empty result;
          do not silently shift it onto unrelated old data.
        - Write valid PostgreSQL: every query reads FROM invoice_lines, and a window
          function cannot appear in WHERE — compute it in a CTE and filter outside.
        - Always give aggregate columns clear aliases (e.g. SELECT sum(total_amount) AS turnover).
        - Order results sensibly and keep them reasonably small.
        - TIN values are strings (e.g. 'A_00000001').
        - The conversation may include earlier turns. When the question refers
          back to a previous request or its result ("the previous query",
          "those invoices", "same but by month", "add the TIN column"), start
          from the most recent prior SQL and adjust it — do NOT ignore the
          reference and fall back to selecting everything.

        Respond with a strict JSON object only:
        {"sql": "<the SQL, or null when no query is needed>", "answer": "<a direct reply when no query is needed, else null>", "explanation": "<one sentence describing what the SQL returns>"}
        PROMPT;
    }

    /**
     * Real-world clock + the actual date span of the loaded data, injected into
     * the prompt so the model knows "today" (it otherwise defaults to its
     * training cutoff) and can reconcile it with the historical snapshot.
     */
    private function dataContext(?string $tin = null): string
    {
        $now = now();
        $lines = [
            '- The current real-world date is '.$now->format('l, j F Y')
                .' ('.$now->format('Y-m-d H:i').', timezone '.config('app.timezone').').',
        ];

        try {
            $span = DB::connection('pgsql_ro')->selectOne(
                'SELECT min(invoice_date) AS min_d, max(invoice_date) AS max_d, count(*) AS n,'
                .' count(DISTINCT invoice_key) AS invoices FROM invoice_lines'
            );
            if ($span && $span->n > 0) {
                $lines[] = '- The invoice data is historical: it covers '.$span->min_d.' … '.$span->max_d
                    .' ('.number_format((int) $span->n).' invoice lines, '.number_format((int) $span->invoices)
                    .' identified invoices). There is no data for the current date.';
            } else {
                $lines[] = '- The invoice table is currently empty.';
            }
        } catch (Throwable) {
            // Coverage is best-effort; current date alone is still useful.
        }

        if ($tin !== null) {
            try {
                // Counts only — the VÖEN itself stays on our side.
                $own = DB::connection('pgsql_ro')->selectOne(
                    'SELECT count(*) FILTER (WHERE supplier_tin = ?) AS sold, count(*) FILTER (WHERE recipient_tin = ?) AS bought FROM invoice_lines',
                    [$tin, $tin],
                );
                $lines[] = '- The selected taxpayer (:tin) has '.number_format((int) $own->sold).' invoice lines as the seller and '
                    .number_format((int) $own->bought).' as the buyer.';
            } catch (Throwable) {
                // Best-effort, like the coverage above.
            }
        }

        return implode("\n", $lines);
    }
}
