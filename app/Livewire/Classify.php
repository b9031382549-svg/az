<?php

namespace App\Livewire;

use App\Jobs\ClassifyItemJob;
use App\Models\Classification;
use App\Models\ImportBatch;
use App\Models\LlmUsage;
use App\Services\Import\ItemFileParser;
use App\Support\Audit;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.app-layout', ['title' => 'Classify'])]
class Classify extends Component
{
    use WithFileUploads;

    /** Max items accepted from one manual (textarea) submission. */
    private const MANUAL_LIMIT = 20;

    /** Max items queued from a single file upload. */
    private const FILE_LIMIT = 10000;

    /** Jobs pushed to the queue per bulk insert. */
    private const DISPATCH_CHUNK = 500;

    public string $input = '';

    public $file;

    /**
     * The upload currently being classified in the background, or null.
     *
     * @var array{batch:string, count:int, total:int, source:string, label:string}|null
     */
    public ?array $queued = null;

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

    /**
     * Queue the textarea items for background classification (one job each) and
     * hand off to the live progress panel — so the request returns immediately
     * instead of blocking on the LLM for every line.
     */
    public function run(): void
    {
        $lines = collect(preg_split('/\r?\n/', $this->input) ?: [])
            ->map(fn ($l) => trim($l))
            ->filter()
            ->unique()
            ->take(self::MANUAL_LIMIT)
            ->values();

        if ($lines->isEmpty()) {
            return;
        }

        $batch = (string) Str::uuid();
        ImportBatch::create([
            'key' => $batch,
            'label' => 'Manual entry',
            'source' => 'manual',
            'user_id' => auth()->id(),
            'item_count' => $lines->count(),
        ]);

        foreach ($lines as $line) {
            ClassifyItemJob::dispatch($line, $batch);
        }

        Audit::log('classify.manual', ['count' => $lines->count(), 'batch' => $batch]);

        $this->queued = [
            'batch' => $batch,
            'count' => $lines->count(),
            'total' => $lines->count(),
            'source' => 'manual',
            'label' => 'Manual entry',
        ];
        $this->input = '';
    }

    public function classifyFile(ItemFileParser $parser): void
    {
        $this->validate(['file' => 'required|file|max:25600']);

        $ext = strtolower((string) $this->file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $this->addError('file', 'Please upload a .xlsx, .xls or .csv file.');

            return;
        }

        // Parsing + enqueuing up to 10k rows can take a moment (kept under the
        // nginx fastcgi_read_timeout of 120s).
        ini_set('memory_limit', '768M');
        set_time_limit(110);

        $path = $this->file->getRealPath();
        // Parse once (don't load the workbook twice) and de-duplicate: the job is
        // idempotent per (batch, source_text), so duplicate rows would never be
        // re-created and the progress bar would never reach 100%.
        $items = array_values(array_unique($parser->parse($path, self::FILE_LIMIT)));
        $total = count($items);

        if (empty($items)) {
            $this->addError('file', 'No item names found in the file.');

            return;
        }

        $batch = (string) Str::uuid();
        $label = $this->file->getClientOriginalName() ?: 'File import';
        ImportBatch::create([
            'key' => $batch,
            'label' => $label,
            'source' => 'file',
            'user_id' => auth()->id(),
            'item_count' => count($items),
        ]);

        // Push jobs in bulk chunks instead of one dispatch() per row, so enqueuing
        // thousands of items is a handful of round-trips, not thousands.
        foreach (array_chunk($items, self::DISPATCH_CHUNK) as $chunk) {
            Queue::bulk(
                array_map(fn ($text) => new ClassifyItemJob($text, $batch), $chunk),
                '',
                'default',
            );
        }

        Audit::log('classify.file_upload', [
            'file' => $label,
            'queued' => count($items),
            'total' => $total,
            'batch' => $batch,
        ]);

        $this->queued = [
            'batch' => $batch,
            'count' => count($items),
            'total' => $total,
            'source' => 'file',
            'label' => $label,
        ];
        $this->reset('file');
    }

    /** Dismiss the progress panel and start a fresh classification. */
    public function startOver(): void
    {
        $this->reset('queued', 'input', 'file');
    }

    public function render()
    {
        $progress = null;

        if ($this->queued) {
            $batch = $this->queued['batch'];
            $done = Classification::where('batch', $batch)->count();

            $progress = [
                'done' => $done,
                'count' => (int) $this->queued['count'],
                'complete' => $done >= (int) $this->queued['count'],
                'rows' => Classification::where('batch', $batch)
                    ->with('code')
                    ->latest()
                    ->limit(50)
                    ->get(),
            ];
        }

        return view('livewire.classify', [
            'progress' => $progress,
            'stats' => [
                'total' => Classification::count(),
                'auto' => Classification::where('status', 'auto_confirmed')->count(),
                'review' => Classification::where('status', 'needs_review')->count(),
                'tokensAll' => (int) LlmUsage::sum('total_tokens'),
            ],
        ]);
    }
}
