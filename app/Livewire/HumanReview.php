<?php

namespace App\Livewire;

use App\Livewire\Concerns\ConfirmsClassifications;
use App\Models\ActivityLog;
use App\Models\ClassificationItem;
use App\Models\ImportBatch;
use App\Models\RubricatorNode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

// The human queue — everything the classifier could not close on its own (no_match /
// blocked_on_fact / a terminal conflict the web search couldn't settle). Left: the list;
// right: the decision panel (translation, why it's here, what each mechanism proposed,
// pick a candidate or type a code, Confirm / Reject / Skip). Actions reuse the shared
// ConfirmsClassifications trait, so a decision here is identical to one on the decision page.
#[Layout('components.app-layout', ['title' => 'Human review'])]
class HumanReview extends Component
{
    use ConfirmsClassifications;

    /** Actions the "Processed today" tile counts. */
    private const PROCESSED_ACTIONS = ['classification.confirm', 'classification.corrected', 'classification.reject'];

    /** Selected upload (batch key) or null for every upload. */
    #[Url]
    public ?string $batch = null;

    public ?int $selected = null;

    /** Manual code entry in the decision panel. */
    public string $manualCode = '';

    /** How many identical items were auto-confirmed alongside the last confirm (notice). */
    public ?int $twinsConfirmed = null;

    public function updatedBatch(): void
    {
        // Switching upload can drop the selected item out of view — reselect on render.
        $this->selected = null;
    }

    public function selectItem(int $id): void
    {
        $this->selected = $id;
        $this->manualCode = '';
        $this->twinsConfirmed = null;
        $this->resetErrorBag('confirm');
    }

    public function confirm(string $code): void
    {
        $item = $this->currentItem();
        if ($item === null) {
            return;
        }
        if (! $this->applyConfirm($item, $code)) {
            // applyConfirm rejects a code that is neither "99" nor an active catalog position —
            // tell the reviewer instead of silently doing nothing.
            $this->addError('confirm', __('That code can\'t be confirmed — it isn\'t an active 4-digit heading (or 99 for a service).'));

            return;
        }

        // Identical names get the same decision: auto-confirm every other queue item whose
        // normalized name (source_hash) matches this one, with the same code.
        $twins = ClassificationItem::humanQueue()
            ->where('source_hash', $item->source_hash)
            ->whereKeyNot($item->id)
            ->get();
        foreach ($twins as $twin) {
            $this->applyConfirm($twin, $code);
        }
        $this->twinsConfirmed = $twins->count() ?: null;

        // Move to the first item still open (a just-confirmed twin may have been "next").
        $this->selected = $this->queueQuery()->value('id');
        $this->manualCode = '';
    }

    public function confirmManual(): void
    {
        $code = trim($this->manualCode);
        if ($code !== '') {
            $this->confirm($code);
        }
    }

    public function rejectItem(): void
    {
        $item = $this->currentItem();
        if ($item === null) {
            return;
        }
        $next = $this->neighbourAfter($item->id);
        $this->applyReject($item);
        $this->selected = $next ?? $this->queueQuery()->value('id');
        $this->manualCode = '';
        $this->twinsConfirmed = null;
    }

    /** Skip: leave the item untouched (per the decision, no DB write) and move on. */
    public function skip(): void
    {
        if ($this->selected === null) {
            return;
        }
        $ids = $this->queueQuery()->pluck('id');
        $pos = $ids->search($this->selected);
        $this->selected = ($pos === false) ? $ids->first() : ($ids->get($pos + 1) ?? $ids->first());
        $this->manualCode = '';
        $this->twinsConfirmed = null;
    }

    /** @return Builder<ClassificationItem> */
    private function queueQuery()
    {
        return ClassificationItem::humanQueue()
            ->when($this->batch !== null, fn ($q) => $q->where('batch', $this->batch))
            ->orderBy('created_at')
            ->orderBy('id');
    }

    private function currentItem(): ?ClassificationItem
    {
        return $this->selected === null
            ? null
            : ClassificationItem::with(['results', 'translation', 'finalCode'])->find($this->selected);
    }

    /** The id that follows $id in the current queue order (for "advance to next"). */
    private function neighbourAfter(int $id): ?int
    {
        $ids = $this->queueQuery()->pluck('id');
        $pos = $ids->search($id);

        return $pos === false ? null : $ids->get($pos + 1);
    }

    public function render()
    {
        $queue = $this->queueQuery()
            ->with(['results', 'translation', 'finalCode'])
            ->limit(200)
            ->get();

        // Default the selection to the first open item; keep it if still in the queue.
        if ($this->selected === null || ! $queue->contains('id', $this->selected)) {
            $this->selected = $queue->first()?->id;
        }
        $item = $queue->firstWhere('id', $this->selected)
            ?? ($this->selected ? ClassificationItem::with(['results', 'translation', 'finalCode'])->find($this->selected) : null);

        // Candidate headings for the panel: every 4-digit heading some mechanism proposed
        // for THIS item, plus a name from the rubricator. These are the confirm options.
        $candidates = collect();
        $proposals = collect();
        if ($item) {
            foreach ($item->results as $r) {
                if ($r->matched_code) {
                    $candidates->push((string) mb_substr((string) $r->matched_code, 0, 4));
                    $proposals->push((object) [
                        'mechanism' => $r->mechanism,
                        'heading' => (string) mb_substr((string) $r->matched_code, 0, 4),
                    ]);
                }
                foreach (collect($r->candidates ?? [])->pluck('code')->filter() as $c) {
                    $candidates->push((string) mb_substr((string) $c, 0, 4));
                }
            }
        }
        $candidates = $candidates->filter()->unique()->values();
        $headingNames = $candidates->isEmpty()
            ? collect()
            : RubricatorNode::whereIn('code', $candidates)->get(['code', 'title', 'title_en', 'title_ru'])
                ->mapWithKeys(fn ($n) => [(string) $n->code => $n->localizedTitle()]);

        return view('livewire.human-review', [
            'queue' => $queue,
            'item' => $item,
            'candidates' => $candidates,
            'proposals' => $proposals->unique(fn ($p) => $p->mechanism.$p->heading)->values(),
            'headingNames' => $headingNames,
            'uploads' => $this->uploads(),
            'waiting' => $this->queueQuery()->count(),
            'oldest' => $this->queueQuery()->min('created_at'),
            'processedToday' => ActivityLog::whereIn('action', self::PROCESSED_ACTIONS)
                ->whereDate('created_at', now()->toDateString())->count(),
        ]);
    }

    /**
     * Uploads that still have open items — the batch selector. Only UUID keys carry an
     * import_batches label (a seed/CLI key like "gold-ivan" has none — avoid the 22P02).
     *
     * @return Collection<int, object>
     */
    private function uploads(): Collection
    {
        $rows = ClassificationItem::humanQueue()
            ->whereNotNull('batch')
            ->selectRaw('batch, count(*) as open')
            ->groupBy('batch')
            ->orderByRaw('count(*) desc')
            ->limit(50)
            ->get();

        $uuidKeys = $rows->pluck('batch')->filter(fn ($b) => Str::isUuid((string) $b))->values();
        $labels = $uuidKeys->isEmpty()
            ? collect()
            : ImportBatch::whereIn('key', $uuidKeys)->pluck('label', 'key');

        return $rows->map(fn ($r) => (object) [
            'key' => (string) $r->batch,
            'label' => $labels[$r->batch] ?? 'Earlier import',
            'open' => (int) $r->open,
        ]);
    }
}
