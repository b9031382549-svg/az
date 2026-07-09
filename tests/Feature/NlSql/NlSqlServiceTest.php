<?php

namespace Tests\Feature\NlSql;

use App\Services\Llm\OpenRouterClient;
use App\Services\NlSql\NlSqlService;
use App\Services\NlSql\SchemaContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    private function messagesFor(string $question, array $history): array
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
        $schema->shouldReceive('describe')->andReturn('Table e_invoices:');
        $schema->shouldReceive('allowedTables')->andReturn(['e_invoices']);

        (new NlSqlService($llm, $schema))->ask($question, $history);

        return $captured;
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
            'sql' => 'SELECT seller_name, sum(total_amount) AS turnover FROM e_invoices GROUP BY 1 ORDER BY 2 DESC LIMIT 5',
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
        $this->assertStringContainsString('SELECT seller_name', $messages[2]['content']);
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
}
