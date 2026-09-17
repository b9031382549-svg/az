<?php

namespace App\Livewire;

use App\Models\AnswerCache;
use App\Models\RubricatorNode;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

// Catalog (memory) — the XİF MN tree AS IT LIVES IN MEMORY: category (chapter) →
// 4-digit position → the actual product/service names answered from the cache. Counts
// and leaves come from answer_cache (production scope), NOT the full catalog. Chapters
// and positions are the rubricator; leaves are the verified answers.
#[Layout('components.app-layout', ['title' => 'Catalog (memory)'])]
class Catalog extends Component
{
    /** The services pseudo-chapter (XİF MN has no single heading to descend to). */
    private const SERVICES = 'SV';

    #[Url]
    public string $q = '';

    /** Expanded chapter codes (2-digit, or SV) and position codes (4-digit). */
    public array $openChapters = [];

    public array $openPositions = [];

    public function toggleChapter(string $code): void
    {
        $this->openChapters = in_array($code, $this->openChapters, true)
            ? array_values(array_diff($this->openChapters, [$code]))
            : [...$this->openChapters, $code];
    }

    public function togglePosition(string $code): void
    {
        $this->openPositions = in_array($code, $this->openPositions, true)
            ? array_values(array_diff($this->openPositions, [$code]))
            : [...$this->openPositions, $code];
    }

    public function collapseAll(): void
    {
        $this->openChapters = [];
        $this->openPositions = [];
    }

    public function render()
    {
        $term = trim($this->q);
        $scope = fn () => AnswerCache::where('test_dataset_id', 0);

        // Search short-circuits the tree: a flat, highlightable list of matching memory
        // entries (by product name, or by heading when the term is numeric).
        if ($term !== '') {
            // ILIKE (case-insensitive, trgm-indexed) on Postgres; LIKE on sqlite (tests).
            $likeOp = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $searchCap = 200;
            $results = $scope()
                ->when(preg_match('/^\d+$/', $term),
                    fn ($w) => $w->where('heading', 'like', $term.'%'),
                    fn ($w) => $w->where('name', $likeOp, '%'.$term.'%'))
                ->orderBy('name')
                ->limit($searchCap + 1)
                ->get(['id', 'name', 'heading', 'is_service']);
            $searchTruncated = $results->count() > $searchCap;

            return view('livewire.catalog', [
                'search' => $results->take($searchCap),
                'term' => $term,
                'searchTruncated' => $searchTruncated,
                'searchCap' => $searchCap,
            ]);
        }

        // Chapter counts: GOODS memory entries grouped by the heading's first two digits.
        // Services are counted separately (and shown in their own root), so a service that
        // happens to carry a heading is never double-counted here.
        $byChapter = $scope()->where('is_service', false)->whereNotNull('heading')
            ->selectRaw('substr(heading, 1, 2) as ch, count(*) as c')
            ->groupBy('ch')->pluck('c', 'ch');
        $serviceCount = (int) $scope()->where('is_service', true)->count();

        $chapters = RubricatorNode::where('level', 1)->get(['code', 'title', 'title_en', 'title_ru'])
            ->map(fn ($n) => (object) [
                'code' => (string) $n->code,
                'title' => $n->localizedTitle(),
                'count' => (int) ($byChapter[(string) $n->code] ?? 0),
            ])
            ->filter(fn ($c) => $c->count > 0)
            ->sortBy('code')->values();
        if ($serviceCount > 0) {
            $chapters->push((object) ['code' => self::SERVICES, 'title' => __('Services'), 'count' => $serviceCount]);
        }

        // Positions (4-digit) for the expanded chapters, with per-position counts.
        $goodsOpen = array_values(array_filter($this->openChapters, fn ($c) => $c !== self::SERVICES));
        $posCounts = empty($goodsOpen) ? collect() : $scope()->where('is_service', false)->whereNotNull('heading')
            ->whereIn(DB::raw('substr(heading, 1, 2)'), $goodsOpen)
            ->selectRaw('heading, count(*) as c')->groupBy('heading')->pluck('c', 'heading');
        $positionsByChapter = collect($goodsOpen)->mapWithKeys(function ($ch) use ($posCounts) {
            $codes = $posCounts->keys()->filter(fn ($h) => str_starts_with((string) $h, $ch))->values();
            $titles = RubricatorNode::where('level', 2)->whereIn('code', $codes)->get(['code', 'title', 'title_en', 'title_ru'])
                ->mapWithKeys(fn ($n) => [(string) $n->code => $n->localizedTitle()]);

            return [$ch => $codes->map(fn ($h) => (object) [
                'code' => (string) $h,
                'title' => $titles[(string) $h] ?? '',
                'count' => (int) $posCounts[$h],
            ])->sortBy('code')->values()];
        });

        // Leaves (the actual answers) for the expanded GOODS positions — capped PER position
        // (a single heading could hold thousands of names). Query each open position with
        // leafCap+1 so the view can show the cap instead of loading everything at once.
        $leafCap = 500;
        $leavesByPosition = collect($this->openPositions)->mapWithKeys(fn ($h) => [$h => $scope()
            ->where('is_service', false)->where('heading', $h)
            ->orderBy('name')->limit($leafCap + 1)->get(['id', 'name', 'heading'])]);

        // Services leaves — capped, but surface the cap rather than silently truncating.
        $serviceCap = 500;
        $serviceLeaves = collect();
        $serviceTruncated = false;
        if (in_array(self::SERVICES, $this->openChapters, true)) {
            $serviceLeaves = $scope()->where('is_service', true)->orderBy('name')
                ->limit($serviceCap + 1)->get(['id', 'name', 'heading']);
            $serviceTruncated = $serviceLeaves->count() > $serviceCap;
            $serviceLeaves = $serviceLeaves->take($serviceCap);
        }

        return view('livewire.catalog', [
            'search' => null,
            'chapters' => $chapters,
            'positionsByChapter' => $positionsByChapter,
            'leavesByPosition' => $leavesByPosition,
            'serviceLeaves' => $serviceLeaves,
            'serviceTruncated' => $serviceTruncated,
            'serviceCap' => $serviceCap,
            'leafCap' => $leafCap,
            'servicesCode' => self::SERVICES,
            'total' => (int) $scope()->count(),
        ]);
    }
}
