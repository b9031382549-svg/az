<?php

namespace App\Livewire;

use App\Models\ImportBatch;
use App\Services\Classify\BatchProgress;
use App\Services\Classify\BatchStats;
use App\Services\Classify\ClassificationQueue;
use App\Services\Import\ItemFileParser;
use App\Support\Audit;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.app-layout', ['title' => 'Classify'])]
class Classify extends Component
{
    use WithFileUploads;

    /** Max items accepted from one manual (textarea) submission — same cap as a file. */
    private const MANUAL_LIMIT = 100000;

    /** Max items queued from a single file upload. */
    private const FILE_LIMIT = 100000;

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
     * Queue the textarea items for background classification and hand off to the
     * live progress panel — the request returns immediately instead of blocking
     * on the LLM for every line.
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

        $count = $this->enqueue($lines->all(), $batch);
        Audit::log('classify.manual', ['count' => $count, 'batch' => $batch]);

        $this->queued = [
            'batch' => $batch,
            'count' => $count,
            'total' => $count,
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
            $this->addError('file', __('Please upload a .xlsx, .xls or .csv file.'));

            return;
        }

        // Parsing + enqueuing up to 10k rows can take a moment (kept under the
        // nginx fastcgi_read_timeout of 120s).
        ini_set('memory_limit', '768M');
        set_time_limit(110);

        $path = $this->file->getRealPath();
        // Parse once and de-duplicate up front (items are also de-duped per
        // (batch, source_hash) when the parent rows are created).
        $items = array_values(array_unique($parser->parse($path, self::FILE_LIMIT)));
        $total = count($items);

        if (empty($items)) {
            $this->addError('file', __('No item names found in the file.'));

            return;
        }

        $batch = (string) Str::uuid();
        $label = $this->file->getClientOriginalName() ?: 'File import';
        ImportBatch::create([
            'key' => $batch,
            'label' => $label,
            'source' => 'file',
            'user_id' => auth()->id(),
            'item_count' => $total,
        ]);

        $count = $this->enqueue($items, $batch);
        Audit::log('classify.file_upload', [
            'file' => $label,
            'queued' => $count,
            'total' => $total,
            'batch' => $batch,
        ]);

        $this->queued = [
            'batch' => $batch,
            'count' => $count,
            'total' => $total,
            'source' => 'file',
            'label' => $label,
        ];
        $this->reset('file');
    }

    /** Put the texts on the shared classification pipeline; returns the distinct item count. */
    private function enqueue(array $texts, string $batch): int
    {
        return app(ClassificationQueue::class)->enqueue($texts, $batch);
    }

    /** Dismiss the progress panel and start a fresh classification. */
    public function startOver(): void
    {
        $this->reset('queued', 'input', 'file');
    }

    public function render()
    {
        $progress = null;
        $headingNames = collect();
        $batchStats = null;

        if ($this->queued) {
            $progress = app(BatchProgress::class)->for($this->queued['batch'], (int) $this->queued['count']);
            $headingNames = $progress['headingNames'];
            $batchStats = app(BatchStats::class)->for($this->queued['batch']);
        }

        return view('livewire.classify', [
            'progress' => $progress,
            'batchStats' => $batchStats,
            'headingNames' => $headingNames,
            'manualLimit' => self::MANUAL_LIMIT,
            'fileLimit' => self::FILE_LIMIT,
        ]);
    }
}
