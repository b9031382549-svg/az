<?php

namespace App\Livewire;

use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Models\ClassificationResult;
use App\Models\GoldLabel;
use App\Models\ImportBatch;
use App\Models\RubricatorNode;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.app-layout', ['title' => 'Review queue'])]
class ReviewQueue extends Component
{
    use WithPagination;

    /** Resolutions that still need a human ("open"). */
    private const OPEN = ['review', 'conflict', 'blocked_on_fact'];

    /** Terminal decisions that hold at any code granularity (kept in the 4-digit view). */
    private const HUMAN_DECIDED = ['confirmed', 'rejected', 'blocked_on_fact'];

    /** Resolutions a bulk reject may target (not yet human-decided, not no_match). */
    private const ACTIONABLE = ['agreed', 'review', 'conflict', 'blocked_on_fact'];

    /** Resolution display metadata for the report donut + legend. */
    private const RESOLUTION_META = [
        // "Found" = one bucket for agreed + ai_resolved (the classifier produced an
        // answer, via consensus or the web-search resolver).
        'found' => ['label' => 'Found', 'color' => '#3f6b4f'],
        'confirmed' => ['label' => 'Confirmed', 'color' => '#5b8568'],
        'review' => ['label' => 'Review', 'color' => '#c2872b'],
        'conflict' => ['label' => 'Conflict', 'color' => '#B5462E'],
        'blocked_on_fact' => ['label' => 'Blocked (fact)', 'color' => '#7c5cbf'],
        'no_match' => ['label' => 'No match', 'color' => '#9a9183'],
        'rejected' => ['label' => 'Rejected', 'color' => '#8a8175'],
    ];

    #[Url]
    public string $filter = 'open';

    /** Selected upload (batch key), or "all". */
    #[Url]
    public string $batch = 'all';

    /** Page of the uploads table (5 per page). */
    public int $uploadPage = 1;

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
    }

    public function updatedBatch(): void
    {
        $this->resetPage();
    }

    /** Pick an upload to review (a batch key, or "all") from the uploads table. */
    public function selectBatch(string $key): void
    {
        $this->batch = $key;
        $this->resetPage();
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
        if (! $item) {
            return;
        }

        if (! in_array($code, $item->allowedCodes(), true)) {
            return;
        }

        $cand = CatalogCode::where('code', $code)->first();
        if (! $cand) {
            return;
        }

        $was = $item->final_code;
        $item->update([
            'final_code' => $cand->code,
            'final_catalog_id' => $cand->id,
            'kind' => $cand->kind, // authoritative (99 => service)
            'resolution' => 'confirmed',
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now(),
        ]);

        Audit::log(
            ((string) $was !== $code) ? 'classification.corrected' : 'classification.confirm',
            ['id' => $id, 'code' => $code, 'was' => $was],
            $item,
        );
    }

    public function reject(int $id): void
    {
        ClassificationItem::whereKey($id)->update(['resolution' => 'rejected']);
        Audit::log('classification.reject', ['id' => $id]);
    }

    /** Confirm every item in the selected upload that already has an agreed code. */
    public function confirmAll(): void
    {
        if ($this->batch === 'all') {
            return;
        }

        $updated = ClassificationItem::where('batch', $this->batch)
            ->whereIn('resolution', ['agreed', 'review'])
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
        if ($this->batch === 'all') {
            return;
        }

        $updated = ClassificationItem::where('batch', $this->batch)
            ->whereIn('resolution', self::ACTIONABLE)
            ->update(['resolution' => 'rejected']);

        Audit::log('batch.bulk_rejected', ['batch' => $this->batch, 'updated' => $updated]);
        $this->resetPage();
    }

    /** Delete the selected upload entirely (its items + results + batch record). */
    public function deleteBatch(): void
    {
        if ($this->batch === 'all') {
            return;
        }

        $deleted = ClassificationItem::where('batch', $this->batch)->count();
        ClassificationItem::where('batch', $this->batch)->delete(); // cascades to results
        ImportBatch::where('key', $this->batch)->delete();
        Audit::log('batch.delete', ['batch' => $this->batch, 'deleted' => $deleted]);

        $this->batch = 'all';
        $this->resetPage();
    }

    public function render()
    {
        // Everything now resolves at the 4-digit HS heading — there is no full-code /
        // heading toggle any more, and no async judge. "Found" = auto-resolved (cache /
        // 2-of-3 consensus / web-search); "Needs attention" = a genuine conflict/review
        // a human must decide.
        $scoped = fn () => ClassificationItem::query()
            ->when($this->batch !== 'all', fn ($q) => $q->where('batch', $this->batch));

        $q = $scoped()->with(['finalCode', 'translation', 'results', 'adjudications']);
        match ($this->filter) {
            'found' => $q->whereIn('resolution', ['agreed', 'ai_resolved']),
            'open' => $q->whereIn('resolution', self::OPEN),
            'all' => $q,
            default => $q->where('resolution', $this->filter),
        };
        $items = $q->latest()->paginate(15);

        $rawCounts = $scoped()->selectRaw('resolution, count(*) as c')->groupBy('resolution')->pluck('c', 'resolution');

        // Collapse agreed + ai_resolved into one "Found" bucket for the tabs/donut.
        $counts = collect($rawCounts);
        $counts['found'] = (int) ($rawCounts['agreed'] ?? 0) + (int) ($rawCounts['ai_resolved'] ?? 0);
        $counts = $counts->reject(fn ($v, $k) => in_array($k, ['agreed', 'ai_resolved'], true))
            ->filter(fn ($v) => $v !== 0);

        // Localized catalog names for the candidate dropdowns.
        $codes = $items->getCollection()
            ->flatMap(fn ($item) => $item->results
                ->flatMap(fn ($r) => collect($r->candidates ?? [])->pluck('code')->push($r->matched_code))
                ->push($item->final_code)
                ->push($item->adjudications->sortByDesc('id')->first()?->winning_code))
            ->filter()
            ->map(fn ($c) => (string) $c)
            ->unique()
            ->values();

        $catalogNames = CatalogCode::query()
            ->whereIn('code', $codes)
            ->get(['code', 'name', 'name_en', 'name_ru'])
            ->mapWithKeys(fn ($c) => [(string) $c->code => $c->localizedName()]);

        // Reference ("gold") labels for the items on this page — DISPLAY ONLY, a hint
        // for the human reviewer. NEVER passed to the classifier/adjudicator.
        $goldKeys = $items->getCollection()->mapWithKeys(fn ($it) => [$it->id => GoldLabel::keyFor((string) $it->source_text)]);
        $goldRows = GoldLabel::whereIn('name_key', $goldKeys->values()->unique()->values())->get()->groupBy('name_key');
        $goldByItem = $goldKeys->map(fn ($key) => $goldRows->get($key, collect()))->all();

        // Names for partial results (a 4-digit heading or the "99" service level has no
        // exact catalog row) — resolved from the rubricator. Cover both the item's final
        // code AND any 4-digit code shown in the per-mechanism trace rows (the search
        // resolver / cache write a bare 4-digit heading), so no code is left unlabeled.
        $headingCodes = $items->getCollection()
            ->flatMap(fn ($it) => collect([$it->final_code])->merge($it->results->pluck('matched_code')))
            ->filter(fn ($c) => ($n = mb_strlen((string) $c)) > 0 && $n < 10)->unique()->values();
        $headingNames = RubricatorNode::whereIn('code', $headingCodes)->get(['code', 'title', 'title_en', 'title_ru'])
            ->mapWithKeys(fn ($n) => [(string) $n->code => $n->localizedTitle()]);

        $openCount = collect(self::OPEN)->sum(fn ($r) => (int) ($counts[$r] ?? 0));

        // Uploads table — the recent imports, paginated 5 per page (client picks one).
        $allUploads = $this->batchOptions();
        $uploadTotal = $allUploads->count();
        $uploadPages = max(1, (int) ceil($uploadTotal / 5));
        $this->uploadPage = min(max(1, $this->uploadPage), $uploadPages);
        $uploads = $allUploads->forPage($this->uploadPage, 5)->values();

        return view('livewire.review-queue', [
            'items' => $items,
            'counts' => $counts,
            'openCount' => $openCount,
            'batches' => $allUploads,
            'uploads' => $uploads,
            'uploadPage' => $this->uploadPage,
            'uploadPages' => $uploadPages,
            'uploadTotal' => $uploadTotal,
            'uploadStart' => ($this->uploadPage - 1) * 5,
            'report' => $this->report($scoped, $counts),
            // Convergence at the 4-digit heading — same resolution-aware buckets as the
            // counts, so the widget agrees with the conflict number.
            'agreement' => $this->agreement(4, $this->virtualResolutions(4)),
            'actionableCount' => collect(self::ACTIONABLE)->sum(fn ($r) => (int) ($rawCounts[$r] ?? 0)),
            'catalogNames' => $catalogNames,
            'goldByItem' => $goldByItem,
            'headingNames' => $headingNames,
        ]);
    }

    /**
     * How many items would AGREE (a majority of the mechanisms that ran share the
     * same code) at a given code granularity — 4 digits (HS heading) vs the full
     * 10-digit code. Recomputed from the stored per-mechanism results only; no LLM.
     * Shows how many "conflicts" are just last-digit disagreements within one heading.
     *
     * @return array{n:int, converge:int, diverge:int, no_code:int, total:int}
     */
    private function agreement(int $n, array $buckets): array
    {
        // Tally the SAME resolution-aware buckets the rest of the page uses, so
        // "diverge" equals the genuine conflict count instead of contradicting it
        // (a resolved item is converged; only a still-open, code-diverging item counts).
        $converge = 0;
        $diverge = 0;
        $noCode = 0;
        foreach ($buckets as $b) {
            if ($b === 'conflict') {
                $diverge++;
            } elseif ($b === 'no_match' || $b === 'waiting') {
                $noCode++;
            } else {
                $converge++; // agreed / found / human-decided → settled at this granularity
            }
        }

        return ['n' => $n, 'converge' => $converge, 'diverge' => $diverge, 'no_code' => $noCode, 'total' => count($buckets)];
    }

    /**
     * Per-item resolution recomputed at a code granularity (4-digit heading). Drives
     * the whole "as if we collected 4-digit codes" view: counts, tabs, the donut and
     * which items each tab shows. Human/terminal decisions are kept verbatim;
     * everything else becomes agreed (majority converges), conflict (diverges) or
     * no_match at N digits. Stored data only — no LLM.
     *
     * @return array<int, string> itemId => resolution
     */
    private function virtualResolutions(int $n): array
    {
        $resolved = fn ($a) => $a->where('verdict', 'resolved');
        $items = ClassificationItem::query()
            ->when($this->batch !== 'all', fn ($q) => $q->where('batch', $this->batch))
            ->where('resolution', '!=', 'pending')
            ->withCount(['adjudications', 'adjudications as resolved_adj_count' => $resolved])
            ->get(['id', 'resolution', 'final_code', 'adjudicated_at']);

        // Mechanism codes, to test 4-digit convergence for the still-open items. Count
        // ONLY the authoritative voting mechanisms (same filter Consensus uses) — the
        // post-consensus 'search' resolver and 'cache' write trace rows too, and must not
        // be counted as extra votes here or the widget diverges from the real consensus.
        $enabled = (array) config('classify.mechanisms.enabled', ['vector']);
        $shadow = (array) config('classify.mechanisms.shadow', []);
        $authoritative = array_values(array_diff($enabled, $shadow)) ?: $enabled;

        $codes = ClassificationResult::query()
            ->whereIn('classification_item_id', $items->pluck('id'))
            ->whereIn('mechanism', $authoritative)
            ->get(['classification_item_id as item', 'matched_code as code'])
            ->groupBy('item');

        $map = [];
        foreach ($items as $it) {
            if (in_array($it->resolution, self::HUMAN_DECIDED, true)) {
                $map[$it->id] = $it->resolution; // human/terminal decision stands at any granularity

                continue;
            }
            // Already RESOLVED (consensus/judge produced a code — including a heading or
            // "99" service level) or the judge PROPOSED one: it stays found at any
            // granularity. Heading mode must NOT re-open it as a conflict from the raw
            // mechanism spread — that was the "42 conflicts" bug.
            if (($it->final_code !== null && $it->final_code !== '') || $it->resolved_adj_count > 0) {
                $map[$it->id] = 'agreed';

                continue;
            }
            // Judge dispatched but no verdict yet → waiting (mirrors full mode).
            if ($it->adjudicated_at !== null && (int) $it->adjudications_count === 0) {
                $map[$it->id] = 'waiting';

                continue;
            }
            // Genuinely open: would a majority of mechanisms converge at the 4-digit heading?
            $rs = $codes->get($it->id, collect());
            $coded = $rs->filter(fn ($r) => $r->code !== null && $r->code !== '');
            if ($coded->isEmpty()) {
                $map[$it->id] = 'no_match';

                continue;
            }
            $top = $coded->map(fn ($r) => mb_substr((string) $r->code, 0, $n))->countBy()->max();
            $map[$it->id] = ($top >= 2 && $top >= intdiv($rs->count(), 2) + 1) ? 'agreed' : 'conflict';
        }

        return $map;
    }

    /**
     * Recent uploads for the filter dropdown — derived from the items themselves
     * (so pre-existing batches still appear), labelled from import_batches.
     *
     * @return Collection<int, object>
     */
    private function batchOptions(): Collection
    {
        $rows = ClassificationItem::query()
            ->whereNotNull('batch')
            ->selectRaw('batch, count(*) as total, max(created_at) as last_at')
            ->groupBy('batch')
            ->orderByRaw('max(created_at) desc')
            ->limit(50)
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

        return $rows->map(function ($r) use ($labels, $break) {
            $b = $break->get($r->batch, collect());
            $cnt = fn ($res) => (int) ($b->firstWhere('resolution', $res)->c ?? 0);
            $resolved = $cnt('agreed') + $cnt('ai_resolved') + $cnt('confirmed');
            $review = $cnt('review');
            $conflict = $cnt('conflict') + $cnt('blocked_on_fact');
            $total = (int) $r->total;

            return (object) [
                'key' => $r->batch,
                'label' => $labels[$r->batch] ?? 'Earlier import',
                'total' => $total,
                'last_at' => $r->last_at,
                'resolved' => $resolved,
                'review' => $review,
                'conflict' => $conflict,
                'done' => $total > 0 ? (int) round($resolved / $total * 100) : 0,
            ];
        });
    }

    /**
     * Distribution report for the current scope: resolution donut, good/service
     * split, consensus breakdown and the top HS chapters.
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

        $chapters = $scoped()
            ->whereNotNull('final_code')
            ->selectRaw('substr(final_code, 1, 2) as chapter, count(*) as c')
            ->groupBy('chapter')
            ->orderByDesc('c')
            ->limit(8)
            ->get();

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
            'chapters' => $chapters,
        ];
    }
}
