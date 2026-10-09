<?php

namespace Tests\Feature\NlSql;

use App\Models\EInvoice;
use App\Services\Llm\OpenRouterClient;
use App\Services\NlSql\NlSqlService;
use App\Services\NlSql\SchemaContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class NlSqlServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The service probes pgsql_ro for the data-coverage line; point it at
        // sqlite so the probe fails fast/locally instead of dialling Postgres.
        config()->set('database.connections.pgsql_ro', config('database.connections.'.config('database.default')));
    }

    /**
     * Capture the messages sent to the LLM. The model replies conversationally
     * (sql=null) so ask() short-circuits before touching the DB.
     *
     * @param  array<int, array<string, mixed>>  $history
     * @return array<int, array{role: string, content: string}>
     */
    private function messagesFor(string $question, array $history, ?string $tin = null): array
    {
        $captured = [];

        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')
            ->once()
            ->andReturnUsing(function (array $messages) use (&$captured) {
                $captured = $messages;

                return ['model' => 'm', 'usage' => [], 'latency_ms' => 1, 'raw' => '{}', 'data' => ['sql' => null, 'answer' => 'ok', 'explanation' => null]];
            });

        $schema = Mockery::mock(SchemaContext::class);
        $schema->shouldReceive('describe')->andReturn('Table invoice_lines:');
        $schema->shouldReceive('allowedTables')->andReturn(['invoice_lines']);

        (new NlSqlService($llm, $schema))->ask($question, $history, $tin);

        return $captured;
    }

    /** The service with a model that answers $sql, for the "this taxpayer" checks. */
    private function serviceAnswering(?string $sql): NlSqlService
    {
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->andReturn([
            'model' => 'm', 'usage' => [], 'latency_ms' => 1, 'raw' => '{}',
            'data' => ['sql' => $sql, 'answer' => $sql === null ? 'ok' : null, 'explanation' => 'x'],
        ]);
        $schema = Mockery::mock(SchemaContext::class);
        $schema->shouldReceive('describe')->andReturn('Table invoice_lines:');
        $schema->shouldReceive('allowedTables')->andReturn(['invoice_lines']);

        return new NlSqlService($llm, $schema);
    }

    public function test_the_chat_asks_its_own_model_without_keeping_the_gpu_awake(): void
    {
        config()->set('nlsql.model', 'gpu:base');
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')
            ->once()
            ->withArgs(fn (array $messages, array $options) => $options === ['model' => 'gpu:base', 'touch' => false])
            ->andReturn(['model' => 'base', 'usage' => [], 'latency_ms' => 1, 'raw' => '{}', 'data' => ['sql' => null, 'answer' => 'ok', 'explanation' => null]]);
        $schema = Mockery::mock(SchemaContext::class);
        $schema->shouldReceive('describe')->andReturn('Table invoice_lines:');
        $schema->shouldReceive('allowedTables')->andReturn(['invoice_lines']);

        $result = (new NlSqlService($llm, $schema))->ask('Что ты умеешь?');

        $this->assertSame('ok', $result['answer']);
    }

    public function test_in_taxpayer_mode_the_vöen_never_reaches_the_model(): void
    {
        $messages = $this->messagesFor('Что продаёт этот налогоплательщик?', [], '1808172501');

        $this->assertStringContainsString('THIS TAXPAYER', $messages[0]['content']);
        $this->assertStringContainsString('supplier_tin = :tin', $messages[0]['content']);
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('1808172501', $message['content']);
        }
        // Without a taxpayer the prompt has no such section.
        $this->assertStringNotContainsString('THIS TAXPAYER', $this->messagesFor('total?', [])[0]['content']);
    }

    public function test_the_server_puts_the_vöen_in_place_of_the_placeholder(): void
    {
        // No table: the test's read-only connection is an empty in-memory database.
        $result = $this->serviceAnswering('SELECT :tin AS t WHERE 1 = 1')->ask('who?', [], '1808172501');

        $this->assertNull($result['error']);
        $this->assertSame([['t' => '1808172501']], $result['rows']);
        $this->assertSame('SELECT :tin AS t WHERE 1 = 1', $result['sql']);   // history keeps the placeholder
    }

    public function test_a_query_not_limited_to_the_taxpayer_is_not_run(): void
    {
        $result = $this->serviceAnswering('SELECT 1 AS n WHERE 1 = 1')->ask('how many?', [], '1808172501');

        $this->assertSame(__('The query was not limited to the selected taxpayer — please rephrase the question.'), $result['error']);
        $this->assertSame([], $result['rows']);
    }

    public function test_a_malformed_vöen_is_refused_before_the_model_is_asked(): void
    {
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldNotReceive('jsonWithUsage');
        $schema = Mockery::mock(SchemaContext::class);

        $result = (new NlSqlService($llm, $schema))->ask('x', [], "1' OR '1'='1");

        $this->assertNotNull($result['error']);
    }

    public function test_without_history_only_system_and_current_question_are_sent(): void
    {
        $messages = $this->messagesFor('total turnover?', []);

        $this->assertSame(['system', 'user'], array_column($messages, 'role'));
        $this->assertSame('total turnover?', end($messages)['content']);
    }

    public function test_prior_turns_are_replayed_before_the_current_question(): void
    {
        $history = [[
            'q' => 'top 5 suppliers by turnover',
            'sql' => 'SELECT supplier_name, sum(total_amount) AS turnover FROM invoice_lines GROUP BY 1 ORDER BY 2 DESC LIMIT 5',
            'answer' => null,
            'explanation' => 'The five suppliers with the highest turnover.',
        ]];

        $messages = $this->messagesFor('now show full info for those invoices', $history);

        // system, prior question, prior assistant reply, current question.
        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertSame('top 5 suppliers by turnover', $messages[1]['content']);
        $this->assertSame('now show full info for those invoices', end($messages)['content']);

        // The assistant turn replays the prior SQL in the model's own JSON shape.
        $this->assertStringContainsString('"sql"', $messages[2]['content']);
        $this->assertStringContainsString('SELECT supplier_name', $messages[2]['content']);
    }

    public function test_a_past_turn_whose_sql_todays_guard_rejects_is_not_replayed(): void
    {
        $history = [
            // Asked before the chat moved to the invoice_lines view — replayed, the model
            // copied FROM e_invoices into every new query.
            ['q' => 'turnover by month', 'sql' => "SELECT date_trunc('month', invoice_date) AS month, sum(total_amount) AS turnover FROM e_invoices GROUP BY 1", 'answer' => null, 'explanation' => 'e'],
            ['q' => 'top suppliers', 'sql' => 'SELECT supplier_name, sum(total_amount) AS turnover FROM invoice_lines GROUP BY 1 ORDER BY 2 DESC LIMIT 5', 'answer' => null, 'explanation' => 'e'],
        ];

        $messages = $this->messagesFor('which invoices are loaded?', $history);

        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertSame('top suppliers', $messages[1]['content']);
        // Nothing the model sees — a replayed turn or the rules — names the old table.
        foreach ($messages as $m) {
            $this->assertStringNotContainsString('e_invoices', $m['content']);
        }
    }

    public function test_the_prompt_sends_what_is_loaded_questions_to_the_data(): void
    {
        // Without this rule "which invoices are loaded?" read as small talk: the model
        // replied it had no information instead of querying the uploads.
        $system = $this->messagesFor('which invoices are loaded?', [])[0]['content'];

        $this->assertStringContainsString('WHAT is loaded', $system);
        $this->assertStringContainsString('upload_name', $system);
    }

    public function test_the_prompt_compares_names_through_az_fold(): void
    {
        // Postgres' ILIKE turns I into i, never ı: "qazlı içki" missed QAZLI İÇKİ (2026-10-03).
        $system = $this->messagesFor('qazlı içki nə qədər alınıb?', [])[0]['content'];

        $this->assertStringContainsString("az_fold(item_name) LIKE '%' || az_fold('qazlı içki') || '%'", $system);
        $this->assertStringContainsString('Never use ILIKE or lower()', $system);
    }

    public function test_line_breaks_written_as_backslash_n_do_not_break_the_query(): void
    {
        // Seen on prod's model 2026-10-08: "... AS total \\nFROM invoice_lines" — the backslash
        // is a syntax error in Postgres.
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('jsonWithUsage')->once()->andReturn([
            'model' => 'm', 'usage' => [], 'latency_ms' => 1, 'raw' => '{}',
            // No table: the test's read-only connection is an empty in-memory database.
            'data' => ['sql' => 'SELECT 1 AS n \\nWHERE 1 = 1', 'answer' => null, 'explanation' => 'one'],
        ]);
        $schema = Mockery::mock(SchemaContext::class);
        $schema->shouldReceive('describe')->andReturn('Table invoice_lines:');
        $schema->shouldReceive('allowedTables')->andReturn(['invoice_lines']);

        $result = (new NlSqlService($llm, $schema))->ask('how many lines?');

        $this->assertNull($result['error']);
        $this->assertSame("SELECT 1 AS n \nWHERE 1 = 1", $result['sql']);
        $this->assertSame([['n' => 1]], $result['rows']);
    }

    public function test_turns_with_no_sql_and_no_answer_are_skipped(): void
    {
        $history = [
            ['q' => 'this one broke', 'sql' => null, 'answer' => null, 'explanation' => null],
            ['q' => 'this one worked', 'sql' => 'SELECT 1', 'answer' => null, 'explanation' => 'e'],
        ];

        $messages = $this->messagesFor('follow up', $history);

        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertSame('this one worked', $messages[1]['content']);
    }

    public function test_conversational_turns_are_replayed_by_their_answer(): void
    {
        $history = [[
            'q' => 'what can you do?',
            'sql' => null,
            'answer' => 'I can answer questions about your invoices.',
            'explanation' => null,
        ]];

        $messages = $this->messagesFor('ok, total VAT then', $history);

        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertStringContainsString('"answer"', $messages[2]['content']);
        $this->assertStringContainsString('questions about your invoices', $messages[2]['content']);
    }

    public function test_the_prompt_explains_lines_and_counts_invoices_by_key(): void
    {
        EInvoice::create(['invoice_date' => '2026-09-01', 'invoice_key' => 'MT|1', 'item_name' => 'Divan', 'total_amount' => 10]);
        EInvoice::create(['invoice_date' => '2026-09-02', 'invoice_key' => 'MT|1', 'item_name' => 'Yan masa', 'total_amount' => 20]);
        EInvoice::create(['invoice_date' => '2026-09-03', 'item_name' => 'Noutbuk', 'total_amount' => 30]);

        // Let the read-only probe see this test's in-memory database (same PDO).
        DB::purge('pgsql_ro');
        DB::connection('pgsql_ro')->setPdo(DB::connection()->getPdo());

        $system = $this->messagesFor('how many invoices?', [])[0]['content'];

        $this->assertStringContainsString('COUNT(DISTINCT invoice_key)', $system);
        $this->assertStringContainsString('ai_code', $system);
        // Coverage comes from the chat's own view: 3 lines, 1 identified invoice.
        $this->assertStringContainsString('3 invoice lines, 1 identified invoices', $system);
    }
}
