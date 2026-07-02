<?php

namespace App\Console\Commands;

use App\Services\NlSql\NlSqlService;
use Illuminate\Console\Command;

class AskAi extends Command
{
    protected $signature = 'ai:ask {question* : Natural-language question about the invoices}';

    protected $description = 'Ask a natural-language question; the LLM writes read-only SQL and runs it';

    public function handle(NlSqlService $service): int
    {
        $question = implode(' ', $this->argument('question'));
        $this->info('Q: '.$question);

        $result = $service->ask($question);

        // Conversational reply (no query was run).
        if (! $result['error'] && empty($result['sql']) && ! empty($result['answer'])) {
            $this->newLine();
            $this->line($result['answer']);

            return self::SUCCESS;
        }

        if ($result['sql']) {
            $this->newLine();
            $this->line('<comment>SQL:</comment>');
            $this->line($result['sql']);
        }
        if ($result['explanation']) {
            $this->newLine();
            $this->line('<comment>Explanation:</comment> '.$result['explanation']);
        }

        if ($result['error']) {
            $this->newLine();
            $this->error('Error: '.$result['error']);

            return self::FAILURE;
        }

        $this->newLine();
        if ($result['rows']) {
            $this->table($result['columns'], array_map(
                fn ($row) => array_map(fn ($v) => is_null($v) ? '' : (string) $v, $row),
                $result['rows'],
            ));
            $this->info(count($result['rows']).' row(s).');
        } else {
            $this->warn('No rows returned.');
        }

        return self::SUCCESS;
    }
}
