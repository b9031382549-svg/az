<?php

namespace App\Livewire;

use App\Jobs\ClassifyItemJob;
use App\Models\Classification;
use App\Models\LlmUsage;
use App\Services\Classify\ClassifierService;
use App\Services\Import\ItemFileParser;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.app-layout', ['title' => 'Classify'])]
class Classify extends Component
{
    use WithFileUploads;

    /** Max items queued from a single file upload. */
    private const FILE_LIMIT = 200;

    public string $input = '';

    public $file;

    /** @var array<string, mixed>|null */
    public ?array $queued = null;

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

    public function classifyFile(ItemFileParser $parser): void
    {
        $this->queued = null;
        $this->validate(['file' => 'required|file|max:25600']);

        $ext = strtolower((string) $this->file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $this->addError('file', 'Please upload a .xlsx, .xls or .csv file.');

            return;
        }

        $path = $this->file->getRealPath();
        $total = $parser->count($path);
        $items = $parser->parse($path, self::FILE_LIMIT);

        if (empty($items)) {
            $this->addError('file', 'No item names found in the file.');

            return;
        }

        $batch = (string) Str::uuid();
        foreach ($items as $text) {
            ClassifyItemJob::dispatch($text, $batch);
        }

        $this->queued = ['count' => count($items), 'total' => $total, 'batch' => $batch];
        $this->reset('file');
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
