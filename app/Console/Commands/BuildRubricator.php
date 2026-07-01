<?php

namespace App\Console\Commands;

use App\Models\CatalogCode;
use App\Models\RubricatorNode;
use App\Support\HsChapters;
use Illuminate\Console\Command;

/**
 * Builds the rubricator tree (chapter -> position -> subposition) purely from
 * catalog code prefixes. Goods titles are derived from catalog.name's HS
 * breadcrumb; chapter titles come from the HsChapters reference list; service
 * node titles are left null for `rubricator:generate-titles` (their names are
 * flat, so nothing is derivable). Idempotent — safe to re-run after a catalog
 * re-import.
 */
class BuildRubricator extends Command
{
    protected $signature = 'data:build-rubricator {--fresh : Wipe the rubricator first}';

    protected $description = 'Build the rubricator category tree from the catalog';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            RubricatorNode::query()->delete();
            $this->info('Cleared rubricator_nodes.');
        }

        $goods = CatalogCode::where('kind', 'good')->get(['code', 'chapter', 'position', 'subposition', 'name']);
        $services = CatalogCode::where('kind', 'service')->get(['code', 'position', 'subposition']);

        // Derive goods titles from the HS breadcrumb: heading (4-digit) = the
        // first ':'-segment; subheading (6-digit) = the first dash-level.
        $posSegments = [];
        $subSegments = [];
        foreach ($goods as $g) {
            $segs = $this->segments((string) $g->name);
            $posSegments[$g->position][] = $segs[0] ?? '';
            $subSegments[$g->subposition][] = $segs[1] ?? ($segs[0] ?? '');
        }

        $map = [];   // code => id, to wire parent_id
        $counts = ['chapter' => 0, 'position' => 0, 'subposition' => 0];

        // Level 1 — chapters (goods) + the services root.
        foreach ($goods->pluck('chapter')->filter()->unique()->sort() as $ch) {
            $map[$ch] = $this->upsert($ch, null, 1, HsChapters::title($ch), 'good');
            $counts['chapter']++;
        }
        if ($services->isNotEmpty()) {
            $map['99'] = $this->upsert('99', null, 1, HsChapters::title('99'), 'service');
            $counts['chapter']++;
        }

        // Level 2 — positions.
        $positions = $goods->pluck('position')->merge($services->pluck('position'))->filter()->unique()->sort();
        foreach ($positions as $pos) {
            $chapter = substr($pos, 0, 2);
            $isService = $chapter === '99';
            $title = $isService ? null : $this->mode($posSegments[$pos] ?? []);
            $map[$pos] = $this->upsert($pos, $map[$chapter] ?? null, 2, $title, $isService ? 'service' : 'good');
            $counts['position']++;
        }

        // Level 3 — subpositions.
        $subs = $goods->pluck('subposition')->merge($services->pluck('subposition'))->filter()->unique()->sort();
        foreach ($subs as $sub) {
            $chapter = substr($sub, 0, 2);
            $pos = substr($sub, 0, 4);
            $isService = $chapter === '99';
            $title = $isService ? null : ($this->mode($subSegments[$sub] ?? []) ?: $this->mode($posSegments[$pos] ?? []));
            $map[$sub] = $this->upsert($sub, $map[$pos] ?? null, 3, $title, $isService ? 'service' : 'good');
            $counts['subposition']++;
        }

        $missing = RubricatorNode::whereNull('title')->count();
        $this->info("Rubricator built: {$counts['chapter']} chapters, {$counts['position']} positions, {$counts['subposition']} subpositions.");
        if ($missing > 0) {
            $this->warn("{$missing} nodes need titles — run `php artisan rubricator:generate-titles`.");
        }

        return self::SUCCESS;
    }

    private function upsert(string $code, ?int $parentId, int $level, ?string $title, string $kind): int
    {
        return RubricatorNode::updateOrCreate(
            ['code' => $code],
            ['parent_id' => $parentId, 'level' => $level, 'title' => $title, 'kind' => $kind, 'is_active' => true],
        )->id;
    }

    /**
     * Split an HS breadcrumb name into its ':'-separated segments, stripping the
     * leading dash markers ("– ", "– – ") of each level.
     *
     * @return array<int, string>
     */
    private function segments(string $name): array
    {
        $parts = preg_split('/:/u', $name) ?: [$name];

        return array_values(array_filter(
            array_map(fn ($p) => trim($p, " \t\u{2013}-"), $parts),
            fn ($p) => $p !== '',
        ));
    }

    /**
     * The most common non-empty value — used to pick a representative title when
     * a rubric's leaves carry slightly different breadcrumb wording.
     *
     * @param  array<int, string>  $values
     */
    private function mode(array $values): ?string
    {
        $values = array_filter($values, fn ($v) => $v !== '');
        if ($values === []) {
            return null;
        }
        $counts = array_count_values($values);
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
