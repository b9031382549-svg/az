<?php

namespace App\Livewire;

use App\Livewire\Concerns\ConfirmsClassifications;
use App\Models\ClassificationItem;
use App\Models\EInvoice;
use App\Models\ImportBatch;
use App\Models\RubricatorNode;
use App\Services\Classify\BatchStats;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Two views, one component, chosen by the URL:
//   /review          → the list of uploads (batch === null)
//   /review/{batch}  → one run's report + item table ('all' = every upload aggregated)
// Keeping a single class means every query and action below is shared verbatim
// between the two — the layout differs, the data does not.
#[Layout('components.app-layout', ['title' => 'Review queue'])]
class ReviewQueue extends Component
{
    use ConfirmsClassifications, WithPagination;

    /** Resolutions that still need a human ("open"). */
    private const OPEN = ['conflict', 'blocked_on_fact'];

    /** Terminal decisions that hold at any code granularity (kept in the 4-digit view). */
    private const HUMAN_DECIDED = ['confirmed', 'rejected', 'blocked_on_fact'];

    /** Resolutions a bulk reject may target (auto-resolved but not yet human-decided). */
    private const ACTIONABLE = ['agreed', 'ai_resolved', 'conflict', 'blocked_on_fact'];

    /** Resolution display metadata for the report donut + legend. */
    private const RESOLUTION_META = [
        // "Found" = one bucket for agreed + ai_resolved (the classifier produced an
        // answer, via consensus or the web-search resolver).
        'found' => ['label' => 'Found', 'color' => '#3f6b4f'],
        'confirmed' => ['label' => 'Confirmed', 'color' => '#5b8568'],
        'resolving' => ['label' => 'Searching…', 'color' => '#c98a2b'],
        'conflict' => ['label' => 'Conflict', 'color' => '#B5462E'],
        'blocked_on_fact' => ['label' => 'Blocked (fact)', 'color' => '#7c5cbf'],
        'no_match' => ['label' => 'No match', 'color' => '#9a9183'],
        'rejected' => ['label' => 'Rejected', 'color' => '#8a8175'],
    ];

    /** Selected upload (batch key) or "all" for the run view; null on the list. */
    public ?string $batch = null;

    #[Url]
    public string $filter = 'all';

    /** Uploads-list page size (10 / 25 / 50) and current page. */
    #[Url]
    public int $perPage = 10;

    public int $uploadPage = 1;

    /** Live search: upload label on the list, item name on the run page. */
    #[Url]
    public string $q = '';

    public function mount(?string $batch = null): void
    {
        $this->batch = $batch;
    }

    public function updatedQ(): void
    {
        $this->resetPage();      // item paginator (run page)
        $this->uploadPage = 1;   // uploads list
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->uploadPage = 1;
    }

    /** Open one upload's run page (the row links do this; kept for programmatic callers). */
    public function selectBatch(string $key): mixed
    {
        return $this->redirect(route('review.batch', ['batch' => $key]), navigate: true);
    }

    public function setUploadPage(int $page): void
    {
        $this->uploadPage = max(1, $page);
    }

    /**
     * Confirm an item with the chosen code — keep the agreed pick or (for a
     * conflict) pick which mechanism's answer is right. Only codes some mechanism
     * actually considered for THIS item are allowed.
     */
    public function confirmWith(int $id, string $code): void
    {
        $item = ClassificationItem::with('results')->find($id);
        if ($item) {
            $this->applyConfirm($item, $code);
        }
    }

    public function reject(int $id): void
    {
        $item = ClassificationItem::find($id);
        if ($item) {
            $this->applyReject($item);
        }
    }

    /** Confirm every item in the selected upload that already has an agreed code. */
    public function confirmAll(): void
    {
        if ($this->batch === null || $this->batch === 'all') {
            return;
        }

        $updated = ClassificationItem::where('batch', $this->batch)
            ->whereIn('resolution', ['agreed', 'ai_resolved'])
            ->update([
                'resolution' => 'confirmed',
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
            ]);

        Audit::log('batch.bulk_confirmed', ['batch' => $this->batch, 'updated' => $updated]);
        $this->resetPage();
    }

    /** Reject every still-actionable item in the selected upload. */
    public function rejectAll(): void
    {
        if ($this->batch === null || $this->batch === 'all') {
            return;
        }

        $updated = ClassificationItem::where('batch', $this->batch)
            ->whereIn('resolution', self::ACTIONABLE)
            ->update(['resolution' => 'rejected']);

        Audit::log('batch.bulk_rejected', ['batch' => $this->batch, 'updated' => $updated]);
        $this->resetPage();
    }

    /** Delete the selected upload entirely (its items + results + batch record). */
    public function deleteBatch(): mixed
    {
        if ($this->batch === null || $this->batch === 'all') {
            return null;
        }

        $deleted = ClassificationItem::where('batch', $this->batch)->count();
        ClassificationItem::where('batch', $this->batch)->delete(); // cascades to results
        // An invoice upload's lines outlive its classification run — keep its batch row so the
        // upload stays listed (and deletable) on the Upload page.
        if (! EInvoice::where('import_batch', $this->batch)->exists()) {
            ImportBatch::where('key', $this->batch)->delete();
        }
        Audit::log('batch.delete', ['batch' => $this->batch, 'deleted' => $deleted]);

        // The upload is gone — back to the list.
        return $this->redirect(route('review'), navigate: true);
    }

    public function render()
    {
        return $this->batch === null ? $this->renderList() : $this->renderBatch();
    }

    /** The list of uploads — /review. */
    private function renderList()
    {
        // perPage is a public #[Url] int — a crafted ?perPage=0 would divide by zero below,
        // and any other value bypasses the 10/25/50 selector. Clamp to the allowed set.
        if (! in_array($this->perPage, [10, 25, 50], true)) {
            $this->perPage = 10;
        }

        $allUploads = $this->batchOptions();

        // Live filter by upload name (label).
        $term = trim($this->q);
        if ($term !== '') {
            $allUploads = $allUploads->filter(fn ($u) => mb_stripos((string) $u->label, $term) !== false)->values();
        }

        $uploadTotal = $allUploads->count();
        $uploadPages = max(1, (int) ceil($uploadTotal / $this->perPage));
        $this->uploadPage = min(max(1, $this->uploadPage), $uploadPages);
        $uploads = $allUploads->forPage($this->uploadPage, $this->perPage)->values();

        // The pinned "All uploads" row — exact sums across every upload in scope.
        $allRow = (object) [
            'total' => (int) $allUploads->sum('total'),
            'resolved' => (int) $allUploads->sum('resolved'),
            'memory' => (int) $allUploads->sum('memory'),
        ];

        return view('livewire.review-queue', [
            'uploads' => $uploads,
            'batches' => $allUploads,
            'allRow' => $allRow,
            'uploadPage' => $this->uploadPage,
            'uploadPages' => $uploadPages,
            'uploadTotal' => $uploadTotal,
            'uploadStart' => ($this->uploadPage - 1) * $this->perPage,
        ]);
    }

    /** One run's report + item table — /review/{batch} ('all' aggregates every upload). */
    private function renderBatch()
    {
        // whereNull('test_run_id'): dataset test rows live only in the Testing tab and
        // must never surface in the human review queue, counts, donut or report.
        $scoped = fn () => ClassificationItem::query()
            ->whereNull('test_run_id')
            ->when($this->batch !== 'all', fn ($q) => $q->where('batch', $this->batch));

        $q = $scoped()->with(['finalCode', 'translation', 'results']);
        match ($this->filter) {
            'found' => $q->whereIn('resolution', ['agreed', 'ai_resolved']),
            // "Needs attention" excludes conflicts the web-search resolver is still working
            // on — those live under the 'resolving' filter, not the human queue.
            'open' => $q->whereIn('resolution', self::OPEN)->whereNot(fn ($w) => $w->resolving()),
            'resolving' => $q->resolving(),
            'all' => $q,
            default => $q->where('resolution', $this->filter),
        };

        // Live search by item name — the original AZ text and its en/ru translations,
        // so it matches whatever the reviewer sees. ILIKE on Postgres, LIKE on sqlite.
        $term = trim($this->q);
        if ($term !== '') {
            $likeOp = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $like = '%'.$term.'%';
            $q->where(fn ($w) => $w->where('source_text', $likeOp, $like)
                ->orWhereHas('translation', fn ($t) => $t->where('en', $likeOp, $like)->orWhere('ru', $likeOp, $like)));
        }
        $items = $q->latest()->paginate(15);

        $rawCounts = $scoped()->selectRaw('resolution, count(*) as c')->groupBy('resolution')->pluck('c', 'resolution');

        // Collapse agreed + ai_resolved into one "Found" bucket for the tabs/donut.
        $counts = collect($rawCounts);
        $counts['found'] = (int) ($rawCounts['agreed'] ?? 0) + (int) ($rawCounts['ai_resolved'] ?? 0);

        // Split conflicts the resolver hasn't finished out of 'conflict' into their own
        // 'resolving' bucket, so they never inflate "needs attention" (openCount) — a
        // conflict still under web search is in-flight, not a human's job yet.
        $resolvingCount = $scoped()->resolving()->count();
        $counts['resolving'] = $resolvingCount;
        $counts['conflict'] = max(0, (int) ($counts['conflict'] ?? 0) - $resolvingCount);

        $counts = $counts->reject(fn ($v, $k) => in_array($k, ['agreed', 'ai_resolved'], true))
            ->filter(fn ($v) => $v !== 0);

        // 4-digit heading names for the item answers AND the confirm-dropdown options
        // (each mechanism's proposed code, trimmed to its heading). All names come from
        // the rubricator — the answer is always a 4-digit heading now.
        $headingCodes = $items->getCollection()
            ->flatMap(fn ($it) => collect([$it->final_code])
                ->merge($it->results->flatMap(fn ($r) => collect($r->candidates ?? [])->pluck('code')->push($r->matched_code))))
            ->filter()
            ->map(fn ($c) => (string) mb_substr((string) $c, 0, 4))
            ->unique()->values();
        $headingNames = RubricatorNode::whereIn('code', $headingCodes)->get(['code', 'title', 'title_en', 'title_ru'])
            ->mapWithKeys(fn ($n) => [(string) $n->code => $n->localizedTitle()]);

        $openCount = collect(self::OPEN)->sum(fn ($r) => (int) ($counts[$r] ?? 0));

        // Per-batch funnel + recognition time — only for a single selected upload.
        $batchStats = $this->batch !== 'all' ? app(BatchStats::class)->for($this->batch) : null;

        return view('livewire.review-batch', [
            'items' => $items,
            'counts' => $counts,
            'batchStats' => $batchStats,
            'openCount' => $openCount,
            'batchLabel' => $this->batchLabel(),
            'report' => $this->report($scoped, $counts),
            // Bulk confirm/reject targets — minus the conflicts still under web search
            // (they aren't a settled outcome a human should sweep yet).
            'actionableCount' => collect(self::ACTIONABLE)->sum(fn ($r) => (int) ($rawCounts[$r] ?? 0)) - $resolvingCount,
            'headingNames' => $headingNames,
        ]);
    }

    /** Human-readable name for the selected run. */
    private function batchLabel(): string
    {
        if ($this->batch === 'all') {
            return __('All uploads');
        }

        return Str::isUuid((string) $this->batch)
            ? (string) (ImportBatch::where('key', $this->batch)->value('label') ?? __('Earlier import'))
            : (string) $this->batch;
    }

    /**
     * Recent uploads for the list — derived from the items themselves (so pre-existing
     * batches still appear), labelled from import_batches.
     *
     * @return Collection<int, object>
     */
    private function batchOptions(): Collection
    {
        $rows = ClassificationItem::query()
            ->whereNotNull('batch')
            ->whereNull('test_run_id') // test-run batches ("testrun:{id}") never appear as uploads
            ->selectRaw('batch, count(*) as total, max(created_at) as last_at')
            ->groupBy('batch')
            ->orderByRaw('max(created_at) desc')
            ->limit(200) // headroom so the list search can reach beyond the newest uploads
            ->get();

        // import_batches.key is a UUID column, so only look up UUID batch keys — a
        // non-UUID batch (a seed/CLI batch like "gold-ivan") has no import_batches row
        // and would otherwise make Postgres throw on the whereIn (22P02).
        $uuidKeys = $rows->pluck('batch')->filter(fn ($b) => Str::isUuid((string) $b))->values();
        $labels = $uuidKeys->isEmpty()
            ? collect()
            : ImportBatch::whereIn('key', $uuidKeys)->pluck('label', 'key');

        // Per-batch resolution breakdown for the result bar (resolved / review / conflict).
        $break = ClassificationItem::query()
            ->whereIn('batch', $rows->pluck('batch'))
            ->selectRaw('batch, resolution, count(*) as c')
            ->groupBy('batch', 'resolution')
            ->get()
            ->groupBy('batch');

        // Conflicts still under the web-search resolver, per batch — shown as "searching",
        // not "conflict", so an upload mid-processing doesn't read as all-conflicts.
        $resolvingByBatch = ClassificationItem::query()
            ->whereIn('batch', $rows->pluck('batch'))
            ->resolving()
            ->selectRaw('batch, count(*) as c')
            ->groupBy('batch')
            ->pluck('c', 'batch');

        // Answers this upload promoted into Memory — the authoritative per-item stamp
        // (set by every promotion path). This is the honest "To Memory" number, not the
        // agreed+ai_resolved bucket (which counts answers that were never written back).
        $memoryByBatch = ClassificationItem::query()
            ->whereIn('batch', $rows->pluck('batch'))
            ->whereNotNull('memory_promoted_at')
            ->selectRaw('batch, count(*) as c')
            ->groupBy('batch')
            ->pluck('c', 'batch');

        return $rows->map(function ($r) use ($labels, $break, $resolvingByBatch, $memoryByBatch) {
            $b = $break->get($r->batch, collect());
            $cnt = fn ($res) => (int) ($b->firstWhere('resolution', $res)->c ?? 0);
            $resolved = $cnt('agreed') + $cnt('ai_resolved') + $cnt('confirmed');
            $review = $cnt('review');
            $resolving = (int) ($resolvingByBatch[$r->batch] ?? 0);
            $conflict = max(0, $cnt('conflict') + $cnt('blocked_on_fact') - $resolving);
            $total = (int) $r->total;

            return (object) [
                'key' => $r->batch,
                'label' => $labels[$r->batch] ?? 'Earlier import',
                'total' => $total,
                'last_at' => $r->last_at,
                'resolved' => $resolved,
                'review' => $review,
                'resolving' => $resolving,
                'conflict' => $conflict,
                'memory' => (int) ($memoryByBatch[$r->batch] ?? 0),
                'done' => $total > 0 ? (int) round($resolved / $total * 100) : 0,
            ];
        });
    }

    /**
     * Distribution report for the current scope: resolution donut, good/service
     * split and consensus breakdown.
     *
     * @param  callable():Builder  $scoped
     * @param  Collection<string, int>  $counts
     * @return array<string, mixed>
     */
    private function report(callable $scoped, Collection $counts): array
    {
        $total = (int) $counts->sum();

        $r = 54.0;
        $circ = 2 * M_PI * $r;
        $segments = [];
        $cumulative = 0.0;
        foreach (self::RESOLUTION_META as $key => $meta) {
            $c = (int) ($counts[$key] ?? 0);
            if ($c === 0) {
                continue;
            }
            $len = $total > 0 ? $c / $total * $circ : 0;
            $segments[] = [
                'key' => $key,
                'color' => $meta['color'],
                'label' => $meta['label'],
                'count' => $c,
                'pct' => $total > 0 ? round($c / $total * 100) : 0,
                'len' => $len,
                'gap' => $circ - $len,
                'offset' => -$cumulative,
            ];
            $cumulative += $len;
        }

        $kind = $scoped()->selectRaw('kind, count(*) as c')->groupBy('kind')->pluck('c', 'kind');

        return [
            'total' => $total,
            'donut' => ['r' => $r, 'circ' => $circ, 'segments' => $segments],
            'good' => (int) ($kind['good'] ?? 0),
            'service' => (int) ($kind['service'] ?? 0),
            'consensus' => [
                // "Found" = agreed + ai_resolved + ai_proposed (already merged in full
                // mode); in heading mode the bucket is the converge count ('agreed').
                'found' => (int) ($counts['found'] ?? 0) + (int) ($counts['agreed'] ?? 0),
                'waiting' => (int) ($counts['waiting'] ?? 0),
                'review' => (int) ($counts['review'] ?? 0),
                'conflict' => (int) ($counts['conflict'] ?? 0),
            ],
        ];
    }
}
