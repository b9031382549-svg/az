<?php

namespace App\Livewire;

use App\Models\ChatMessage;
use App\Services\NlSql\NlSqlService;
use App\Support\Audit;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'AI Chat'])]
class AskAi extends Component
{
    /** How many past turns to load into the view. */
    private const HISTORY_LIMIT = 100;

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

    public function mount(): void
    {
        // Restore this user's chat history (chronological), so it survives
        // navigating away and back, or signing out and in.
        $this->messages = ChatMessage::query()
            ->where('user_id', auth()->id())
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (ChatMessage $m) => $this->toMessage($m))
            ->values()
            ->all();
    }

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
        $rows = array_slice($result['rows'], 0, 50);

        $message = ChatMessage::create([
            'user_id' => auth()->id(),
            'question' => $question,
            'answer' => $result['answer'],
            'sql' => $result['sql'],
            'explanation' => $result['explanation'],
            'columns' => $result['columns'],
            'rows' => $rows,
            'truncated' => count($result['rows']) > 50,
            'error' => $result['error'],
        ]);

        $this->messages[] = $this->toMessage($message);
        $this->question = '';

        Audit::log('chat.ask', [
            'question' => $question,
            'has_sql' => $result['sql'] !== null,
            'rows' => count($result['rows']),
            'error' => $result['error'],
        ], $message);
    }

    public function clearHistory(): void
    {
        ChatMessage::where('user_id', auth()->id())->delete();
        $this->messages = [];
    }

    public function render()
    {
        return view('livewire.ask-ai');
    }

    /**
     * Shape a persisted turn into the array the view renders.
     *
     * @return array<string, mixed>
     */
    private function toMessage(ChatMessage $m): array
    {
        return [
            'q' => $m->question,
            'answer' => $m->answer,
            'explanation' => $m->explanation,
            'sql' => $m->sql,
            'columns' => $m->columns ?? [],
            'rows' => $m->rows ?? [],
            'truncated' => (bool) $m->truncated,
            'error' => $m->error,
        ];
    }
}
