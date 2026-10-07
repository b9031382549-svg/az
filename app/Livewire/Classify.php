<?php

namespace App\Livewire;

use App\Models\ImportBatch;
use App\Services\Classify\BatchProgress;
use App\Services\Classify\BatchStats;
use App\Services\Classify\ClassificationQueue;
use App\Support\Audit;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

// A quick check: item names typed or pasted into the text box, classified in the background with
// live progress. Files go through the Upload page — preview, big files, and lines the chat reads.
#[Layout('components.app-layout', ['title' => 'Classify'])]
class Classify extends Component
{
    /** Max items accepted from one manual (textarea) submission. */
    private const MANUAL_LIMIT = 100000;

    public string $input = '';

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

    /** Put the texts on the shared classification pipeline; returns the distinct item count. */
    private function enqueue(array $texts, string $batch): int
    {
        return app(ClassificationQueue::class)->enqueue($texts, $batch);
    }

    /** Dismiss the progress panel and start a fresh classification. */
    public function startOver(): void
    {
        $this->reset('queued', 'input');
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
        ]);
    }
}
