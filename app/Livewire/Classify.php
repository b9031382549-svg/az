<?php

namespace App\Livewire;

use App\Jobs\ClassifyMechanismJob;
use App\Jobs\TranslateItemJob;
use App\Models\ClassificationItem;
use App\Models\ImportBatch;
use App\Models\ItemTranslation;
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

    /**
     * Create one parent ClassificationItem per unique (batch, source_hash) and
     * fan out a ClassifyMechanismJob per enabled mechanism, plus one background
     * translation job per item. Returns the number of distinct items enqueued.
     *
     * @param  array<int, string>  $texts
     */
    private function enqueue(array $texts, string $batch): int
    {
        $enabled = (array) config('classify.mechanisms.enabled', ['vector']);
        $now = now();

        // Parent rows in bulk. keyBy(source_hash) so a single upsert never
        // targets the same (batch, source_hash) row twice.
        $rows = collect($texts)
            ->map(fn ($t) => [
                'batch' => $batch,
                'source_hash' => ItemTranslation::hashFor($t),
                'source_text' => $t,
                'resolution' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->keyBy('source_hash')
            ->values()
            ->all();

        ClassificationItem::upsert($rows, ['batch', 'source_hash'], ['source_text']);

        $ids = ClassificationItem::where('batch', $batch)->pluck('id');

        $jobs = [];
        foreach ($ids as $id) {
            foreach ($enabled as $mechanism) {
                $jobs[] = new ClassifyMechanismJob((int) $id, (string) $mechanism);
            }
        }
        foreach (array_chunk($jobs, self::DISPATCH_CHUNK) as $chunk) {
            Queue::bulk($chunk, '', 'default');
        }

        if (config('classify.translate_items', true)) {
            $translate = collect($texts)->unique()->map(fn ($t) => new TranslateItemJob($t))->all();
            foreach (array_chunk($translate, self::DISPATCH_CHUNK) as $chunk) {
                Queue::bulk($chunk, '', 'default');
            }
        }

        return $ids->count();
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
            $done = ClassificationItem::where('batch', $batch)->where('resolution', '!=', 'pending')->count();

            $progress = [
                'done' => $done,
                'count' => (int) $this->queued['count'],
                'complete' => $done >= (int) $this->queued['count'],
                'rows' => ClassificationItem::where('batch', $batch)
                    ->with(['finalCode', 'translation', 'results'])
                    ->latest()
                    ->limit(50)
                    ->get(),
            ];
        }

        return view('livewire.classify', [
            'progress' => $progress,
            'fileLimit' => self::FILE_LIMIT,
            'stats' => [
                'total' => ClassificationItem::count(),
                'auto' => ClassificationItem::where('resolution', 'agreed')->count(),
                'review' => ClassificationItem::whereIn('resolution', ['review', 'conflict', 'blocked_on_fact'])->count(),
                'tokensAll' => (int) LlmUsage::sum('total_tokens'),
            ],
        ]);
    }
}
