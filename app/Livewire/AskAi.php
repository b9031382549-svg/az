<?php

namespace App\Livewire;

use App\Models\ChatMessage;
use App\Services\NlSql\NlSqlService;
use App\Services\NlSql\Taxpayers;
use App\Support\Audit;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'AI Chat'])]
class AskAi extends Component
{
    /** How many past turns to load into the view. */
    private const HISTORY_LIMIT = 100;

    /** How many recent turns to feed the model as conversational context. */
    private const CONTEXT_TURNS = 5;

    public string $question = '';

    /**
     * "This taxpayer" mode: the VÖEN every question is about ('' = the whole data). In the URL, so
     * the Invoices page can open the chat on a taxpayer. The VÖEN never goes to the model.
     */
    #[Url(as: 'tin', except: '')]
    public string $tin = '';

    /** The VÖEN being typed into the "choose a taxpayer" field. */
    public string $tinInput = '';

    /** @var array<int, string> */
    public array $taxpayerSuggestions = [
        'What does this taxpayer sell?',
        'What does this taxpayer buy?',
        'Who are its counterparties?',
        'Its categories by month',
    ];

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
        // A link may carry a VÖEN that is not in the data (any more) — drop it rather than ask about nothing.
        if ($this->tin !== '' && app(Taxpayers::class)->find($this->tin) === null) {
            $this->tin = '';
        }

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

    /** Ask about one taxpayer from now on — from the field, or a VÖEN clicked in an answer. */
    public function setContext(string $tin): void
    {
        $taxpayer = app(Taxpayers::class)->find($tin);
        if ($taxpayer === null) {
            $this->addError('tinInput', __('No lines with this VÖEN in the loaded data.'));

            return;
        }

        $this->tin = $taxpayer['tin'];
        $this->reset('tinInput');
        $this->resetErrorBag('tinInput');
    }

    public function chooseTin(): void
    {
        $this->setContext($this->tinInput);
    }

    public function clearContext(): void
    {
        $this->tin = '';
    }

    public function ask(NlSqlService $service): void
    {
        $question = trim($this->question);
        if ($question === '') {
            return;
        }

        // Feed the recent turns as context BEFORE this one is appended, so the
        // model can resolve follow-ups ("from the previous query").
        $result = $service->ask($question, $this->recentContext(), $this->tin !== '' ? $this->tin : null);
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
            'context_tin' => $this->tin !== '' ? $this->tin : null,
        ]);

        $this->messages[] = $this->toMessage($message);
        $this->question = '';

        Audit::log('chat.ask', [
            'question' => $question,
            'has_sql' => $result['sql'] !== null,
            'rows' => count($result['rows']),
            'error' => $result['error'],
            'context_tin' => $this->tin !== '' ? $this->tin : null,
        ], $message);
    }

    public function clearHistory(): void
    {
        ChatMessage::where('user_id', auth()->id())->delete();
        $this->messages = [];
    }

    public function render()
    {
        return view('livewire.ask-ai', [
            'taxpayer' => $this->tin !== '' ? app(Taxpayers::class)->find($this->tin) : null,
        ]);
    }

    /**
     * The last few successful turns (oldest→newest) handed to NlSqlService as
     * conversational context, so follow-ups like "from the previous query"
     * resolve — only turns about the same taxpayer (or all of them about the
     * whole data): another context's SQL would steer the model wrong.
     * Clearing the history empties $messages, which resets context.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentContext(): array
    {
        return collect($this->messages)
            ->filter(fn (array $m) => ($m['tin'] ?? '') === $this->tin)
            ->filter(fn (array $m) => ($m['error'] ?? null) === null
                && (($m['sql'] ?? null) !== null || ($m['answer'] ?? null) !== null))
            ->slice(-self::CONTEXT_TURNS)
            ->values()
            ->all();
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
            'tin' => (string) ($m->context_tin ?? ''),
        ];
    }
}
