<?php

namespace App\Livewire;

use App\Models\Classification;
use App\Models\ImportBatch;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.app-layout', ['title' => 'Review queue'])]
class ReviewQueue extends Component
{
    use WithPagination;

    /** Statuses that are still actionable (bulk confirm/reject targets). */
    private const PENDING = ['needs_review', 'auto_confirmed'];

    /** Status display metadata for the report donut + legend. */
    private const STATUS_META = [
        'auto_confirmed' => ['label' => 'Auto-confirmed', 'color' => '#3f6b4f'],
        'confirmed' => ['label' => 'Confirmed', 'color' => '#5b8568'],
        'needs_review' => ['label' => 'Needs review', 'color' => '#c2872b'],
        'rejected' => ['label' => 'Rejected', 'color' => '#B5462E'],
        'no_match' => ['label' => 'No match', 'color' => '#9a9183'],
        'error' => ['label' => 'Error', 'color' => '#7c5cbf'],
    ];

    #[Url]
    public string $filter = 'needs_review';

    /** Selected upload (batch key), or "all". */
    #[Url]
    public string $batch = 'all';

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
    }

    public function updatedBatch(): void
    {
        $this->resetPage();
    }

    public function confirm(int $id): void
    {
        Classification::whereKey($id)->update(['status' => 'confirmed']);
        Audit::log('classification.confirm', ['id' => $id]);
    }

    public function reject(int $id): void
    {
        Classification::whereKey($id)->update(['status' => 'rejected']);
        Audit::log('classification.reject', ['id' => $id]);
    }

    /** Confirm every still-pending item in the selected upload. */
    public function confirmAll(): void
    {
        $this->bulkUpdate('confirmed');
    }

    /** Reject every still-pending item in the selected upload. */
    public function rejectAll(): void
    {
        $this->bulkUpdate('rejected');
    }

    /** Delete the selected upload entirely (its items + the batch record). */
    public function deleteBatch(): void
    {
        if ($this->batch === 'all') {
            return;
        }

        $deleted = Classification::where('batch', $this->batch)->count();
        Classification::where('batch', $this->batch)->delete();
        ImportBatch::where('key', $this->batch)->delete();
        Audit::log('batch.delete', ['batch' => $this->batch, 'deleted' => $deleted]);

        $this->batch = 'all';
        $this->resetPage();
    }

    private function bulkUpdate(string $status): void
    {
        if ($this->batch === 'all') {
            return; // bulk actions are scoped to a single upload, never "all"
        }

        $updated = Classification::where('batch', $this->batch)
            ->whereIn('status', self::PENDING)
            ->update(['status' => $status]);

        Audit::log('batch.bulk_'.$status, ['batch' => $this->batch, 'updated' => $updated]);
        $this->resetPage();
    }

    public function render()
    {
        // Scope the list, the tab counts and the report to the selected upload.
        $scoped = fn () => Classification::query()
            ->when($this->batch !== 'all', fn ($q) => $q->where('batch', $this->batch));

        $items = $scoped()
            ->with('code')
            ->when($this->filter !== 'all', fn ($q) => $q->where('status', $this->filter))
            ->latest()
            ->paginate(15);

        $counts = $scoped()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return view('livewire.review-queue', [
            'items' => $items,
            'counts' => $counts,
            'batches' => $this->batchOptions(),
            'report' => $this->report($scoped, $counts),
            'pendingCount' => ($counts['needs_review'] ?? 0) + ($counts['auto_confirmed'] ?? 0),
        ]);
    }

    /**
     * Recent uploads for the filter dropdown — derived from the classifications
     * themselves (so pre-existing batches still appear), labelled from
     * import_batches where a record exists.
     *
     * @return Collection<int, object>
     */
    private function batchOptions(): Collection
    {
        $rows = Classification::query()
            ->whereNotNull('batch')
            ->selectRaw('batch, count(*) as total, max(created_at) as last_at')
            ->groupBy('batch')
            ->orderByRaw('max(created_at) desc')
            ->limit(50)
            ->get();

        $labels = ImportBatch::whereIn('key', $rows->pluck('batch'))->pluck('label', 'key');

        return $rows->map(fn ($r) => (object) [
            'key' => $r->batch,
            'label' => $labels[$r->batch] ?? 'Earlier import',
            'total' => (int) $r->total,
            'last_at' => $r->last_at,
        ]);
    }

    /**
     * Distribution report for the current scope: status donut, good/service
     * split, confidence buckets and the top HS chapters.
     *
     * @param  callable():\Illuminate\Database\Eloquent\Builder  $scoped
     * @param  Collection<string,int>  $counts
     * @return array<string, mixed>
     */
    private function report(callable $scoped, Collection $counts): array
    {
        $total = (int) $counts->sum();

        // Status donut segments (SVG stroke-dasharray).
        $r = 54.0;
        $circ = 2 * M_PI * $r;
        $segments = [];
        $cumulative = 0.0;
        foreach (self::STATUS_META as $key => $meta) {
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

        $conf = $scoped()->selectRaw(
            'count(*) filter (where confidence >= 0.85) as high,
             count(*) filter (where confidence >= 0.6 and confidence < 0.85) as mid,
             count(*) filter (where confidence < 0.6 or confidence is null) as low'
        )->first();

        $chapters = $scoped()
            ->whereNotNull('matched_code')
            ->selectRaw('substr(matched_code, 1, 2) as chapter, count(*) as c')
            ->groupBy('chapter')
            ->orderByDesc('c')
            ->limit(8)
            ->get();

        return [
            'total' => $total,
            'donut' => ['r' => $r, 'circ' => $circ, 'segments' => $segments],
            'good' => (int) ($kind['good'] ?? 0),
            'service' => (int) ($kind['service'] ?? 0),
            'conf' => [
                'high' => (int) ($conf->high ?? 0),
                'mid' => (int) ($conf->mid ?? 0),
                'low' => (int) ($conf->low ?? 0),
            ],
            'chapters' => $chapters,
        ];
    }
}
