<?php

namespace App\Livewire;

use App\Services\NlSql\NlSqlService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'AI Chat'])]
class AskAi extends Component
{
    public string $question = '';

    /** @var array<int, array<string, mixed>> */
    public array $messages = [];

    /** @var array<int, string> */
    public array $suggestions = [
        'What is the total turnover and VAT?',
        'Show turnover by month',
        'Top 5 suppliers by turnover',
        'How many invoices have no VAT?',
    ];

    public function suggest(string $text): void
    {
        $this->question = $text;
    }

    public function ask(NlSqlService $service): void
    {
        $question = trim($this->question);
        if ($question === '') {
            return;
        }

        $result = $service->ask($question);

        $this->messages[] = [
            'q' => $question,
            'explanation' => $result['explanation'],
            'sql' => $result['sql'],
            'columns' => $result['columns'],
            'rows' => array_slice($result['rows'], 0, 50),
            'truncated' => count($result['rows']) > 50,
            'error' => $result['error'],
        ];

        $this->question = '';
    }

    public function render()
    {
        return view('livewire.ask-ai');
    }
}
