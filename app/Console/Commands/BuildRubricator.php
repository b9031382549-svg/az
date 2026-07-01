<?php

namespace App\Console\Commands;

use App\Models\CatalogCode;
use App\Models\RubricatorNode;
use App\Support\HsChapters;
use App\Support\ServiceRubrics;
use Illuminate\Console\Command;

/**
 * Builds the rubricator tree (chapter -> position -> subposition) purely from
 * catalog code prefixes. Titles: goods positions/subpositions from catalog.name's
 * HS breadcrumb; goods chapters from the HsChapters reference list; service
 * positions from the ServiceRubrics reference list; service subpositions derived
 * from their own (flat) leaf names. Idempotent — safe to re-run after a re-import.
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
        $services = CatalogCode::where('kind', 'service')->get(['code', 'position', 'subposition', 'name']);

        // Derive goods titles from the HS breadcrumb: heading (4-digit) = the
        // first ':'-segment; subheading (6-digit) = the first dash-level.
        $posSegments = [];
        $subSegments = [];
        foreach ($goods as $g) {
            $segs = $this->segments((string) $g->name);
            $posSegments[$g->position][] = $segs[0] ?? '';
            $subSegments[$g->subposition][] = $segs[1] ?? ($segs[0] ?? '');
        }

        // Service subposition titles are derived from their own leaf names.
        $svcSubNames = [];
        foreach ($services as $s) {
            $svcSubNames[$s->subposition][] = (string) $s->name;
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
            $title = $isService ? ServiceRubrics::title($pos) : $this->mode($posSegments[$pos] ?? []);
            $map[$pos] = $this->upsert($pos, $map[$chapter] ?? null, 2, $title, $isService ? 'service' : 'good');
            $counts['position']++;
        }

        // Level 3 — subpositions.
        $subs = $goods->pluck('subposition')->merge($services->pluck('subposition'))->filter()->unique()->sort();
        foreach ($subs as $sub) {
            $chapter = substr($sub, 0, 2);
            $pos = substr($sub, 0, 4);
            $isService = $chapter === '99';
            $title = $isService
                ? ($this->serviceSubTitle($svcSubNames[$sub] ?? []) ?: ServiceRubrics::title($pos))
                : ($this->mode($subSegments[$sub] ?? []) ?: $this->mode($posSegments[$pos] ?? []));
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
     * A representative title for a service subposition, taken from its own leaf
     * names (services are flat, so a leaf name IS the description). Prefers a
     * specific leaf over a generic "others" one; picks the shortest as the label.
     *
     * @param  array<int, string>  $names
     */
    private function serviceSubTitle(array $names): ?string
    {
        $names = array_values(array_filter(array_map('trim', $names), fn ($n) => $n !== ''));
        if ($names === []) {
            return null;
        }

        $generic = ['digərləri', 'sair', 'sair xidmətlər', 'digər'];
        $specific = array_values(array_filter(
            $names,
            fn ($n) => ! in_array(mb_strtolower($n), $generic, true) && mb_strlen($n) >= 4,
        ));
        $pool = $specific !== [] ? $specific : $names;
        usort($pool, fn ($a, $b) => mb_strlen($a) <=> mb_strlen($b));

        return mb_substr($pool[0], 0, 90);
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
