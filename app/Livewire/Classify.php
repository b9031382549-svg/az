<?php

namespace App\Livewire;

use App\Models\Classification;
use App\Models\LlmUsage;
use App\Services\Classify\ClassifierService;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Classify'])]
class Classify extends Component
{
    public string $input = '';

    /** @var array<int, array<string, mixed>> */
    public array $results = [];

    public ?int $tokens = null;

    /** @var array<int, string> */
    public array $examples = [
        'Şpris 5ml 23G Х32 MM 3H rezin porşenli',
        'Anilin və onun duzları',
        'Taxılın topdansatışı üzrə xidmətlər',
    ];

    public function useExample(string $text): void
    {
        $this->input = trim($this->input."\n".$text);
    }

    public function run(ClassifierService $classifier): void
    {
        $lines = collect(preg_split('/\r?\n/', $this->input) ?: [])
            ->map(fn ($l) => trim($l))
            ->filter()
            ->take(20)
            ->values();

        if ($lines->isEmpty()) {
            return;
        }

        $batch = (string) Str::uuid();
        $this->results = [];
        $tokens = 0;

        foreach ($lines as $line) {
            $result = $classifier->classify($line);
            $classifier->record($result, $batch);
            $tokens += $result['usage']['total_tokens'] ?? 0;
            $this->results[] = $result;
        }

        $this->tokens = $tokens;
    }

    public function render()
    {
        return view('livewire.classify', [
            'stats' => [
                'total' => Classification::count(),
                'auto' => Classification::where('status', 'auto_confirmed')->count(),
                'review' => Classification::where('status', 'needs_review')->count(),
                'tokensAll' => (int) LlmUsage::sum('total_tokens'),
            ],
        ]);
    }
}
