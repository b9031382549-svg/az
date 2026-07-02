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

        $goods = CatalogCode::where('kind', 'good')->get(['code', 'chapter', 'position', 'subposition', 'name', 'name_en', 'name_ru']);
        $services = CatalogCode::where('kind', 'service')->get(['code', 'position', 'subposition', 'name', 'name_en', 'name_ru']);

        // The three display languages, and the catalog column each reads from.
        // en/ru are derived from the already-translated catalog (no LLM); a null
        // translation leaves the node's localized title empty (falls back to az).
        $langs = ['az' => 'name', 'en' => 'name_en', 'ru' => 'name_ru'];

        // Derive goods titles from the HS breadcrumb: heading (4-digit) = the
        // first ':'-segment; subheading (6-digit) = the first dash-level. One
        // segment pool per language, so each locale gets its own representative.
        $posSegments = ['az' => [], 'en' => [], 'ru' => []];
        $subSegments = ['az' => [], 'en' => [], 'ru' => []];
        foreach ($goods as $g) {
            foreach ($langs as $lang => $col) {
                $name = trim((string) ($g->{$col} ?? ''));
                if ($name === '') {
                    continue;
                }
                $segs = $this->segments($name);
                $posSegments[$lang][$g->position][] = $segs[0] ?? '';
                $subSegments[$lang][$g->subposition][] = $segs[1] ?? ($segs[0] ?? '');
            }
        }

        // Service subposition titles are derived from their own leaf names.
        $svcSubNames = ['az' => [], 'en' => [], 'ru' => []];
        foreach ($services as $s) {
            foreach ($langs as $lang => $col) {
                $name = trim((string) ($s->{$col} ?? ''));
                if ($name === '') {
                    continue;
                }
                $svcSubNames[$lang][$s->subposition][] = $name;
            }
        }

        $map = [];   // code => id, to wire parent_id
        $counts = ['chapter' => 0, 'position' => 0, 'subposition' => 0];

        // Level 1 — chapters (goods) + the services root.
        foreach ($goods->pluck('chapter')->filter()->unique()->sort() as $ch) {
            $map[$ch] = $this->upsert($ch, null, 1, [
                'title' => HsChapters::title($ch),
                'title_en' => HsChapters::title($ch, 'en'),
                'title_ru' => HsChapters::title($ch, 'ru'),
            ], 'good');
            $counts['chapter']++;
        }
        if ($services->isNotEmpty()) {
            $map['99'] = $this->upsert('99', null, 1, [
                'title' => HsChapters::title('99'),
                'title_en' => HsChapters::title('99', 'en'),
                'title_ru' => HsChapters::title('99', 'ru'),
            ], 'service');
            $counts['chapter']++;
        }

        // Level 2 — positions.
        $positions = $goods->pluck('position')->merge($services->pluck('position'))->filter()->unique()->sort();
        foreach ($positions as $pos) {
            $chapter = substr($pos, 0, 2);
            $isService = $chapter === '99';
            $titles = $isService
                ? [
                    'title' => ServiceRubrics::title($pos),
                    'title_en' => ServiceRubrics::title($pos, 'en'),
                    'title_ru' => ServiceRubrics::title($pos, 'ru'),
                ]
                : [
                    'title' => $this->mode($posSegments['az'][$pos] ?? []),
                    'title_en' => $this->mode($posSegments['en'][$pos] ?? []),
                    'title_ru' => $this->mode($posSegments['ru'][$pos] ?? []),
                ];
            $map[$pos] = $this->upsert($pos, $map[$chapter] ?? null, 2, $titles, $isService ? 'service' : 'good');
            $counts['position']++;
        }

        // Level 3 — subpositions.
        $subs = $goods->pluck('subposition')->merge($services->pluck('subposition'))->filter()->unique()->sort();
        foreach ($subs as $sub) {
            $chapter = substr($sub, 0, 2);
            $pos = substr($sub, 0, 4);
            $isService = $chapter === '99';
            $titles = $isService
                ? [
                    'title' => $this->serviceSubTitle($svcSubNames['az'][$sub] ?? []) ?: ServiceRubrics::title($pos),
                    'title_en' => $this->serviceSubTitle($svcSubNames['en'][$sub] ?? []) ?: ServiceRubrics::title($pos, 'en'),
                    'title_ru' => $this->serviceSubTitle($svcSubNames['ru'][$sub] ?? []) ?: ServiceRubrics::title($pos, 'ru'),
                ]
                : [
                    'title' => $this->mode($subSegments['az'][$sub] ?? []) ?: $this->mode($posSegments['az'][$pos] ?? []),
                    'title_en' => $this->mode($subSegments['en'][$sub] ?? []) ?: $this->mode($posSegments['en'][$pos] ?? []),
                    'title_ru' => $this->mode($subSegments['ru'][$sub] ?? []) ?: $this->mode($posSegments['ru'][$pos] ?? []),
                ];
            $map[$sub] = $this->upsert($sub, $map[$pos] ?? null, 3, $titles, $isService ? 'service' : 'good');
            $counts['subposition']++;
        }

        $missing = RubricatorNode::whereNull('title')->count();
        $this->info("Rubricator built: {$counts['chapter']} chapters, {$counts['position']} positions, {$counts['subposition']} subpositions.");
        if ($missing > 0) {
            $this->warn("{$missing} nodes need titles — run `php artisan rubricator:generate-titles`.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{title: ?string, title_en: ?string, title_ru: ?string}  $titles
     */
    private function upsert(string $code, ?int $parentId, int $level, array $titles, string $kind): int
    {
        return RubricatorNode::updateOrCreate(
            ['code' => $code],
            [
                'parent_id' => $parentId,
                'level' => $level,
                'title' => $titles['title'] ?? null,
                'title_en' => $titles['title_en'] ?? null,
                'title_ru' => $titles['title_ru'] ?? null,
                'kind' => $kind,
                'is_active' => true,
            ],
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

        // Strip leading/trailing whitespace and the breadcrumb dash markers
        // ("– ", "- ") Unicode-safely. A byte-wise trim() mask would slice
        // multibyte (e.g. Cyrillic) characters mid-sequence and corrupt them.
        return array_values(array_filter(
            array_map(fn ($p) => (string) preg_replace('/^[\s\x{2013}\-]+|[\s\x{2013}\-]+$/u', '', $p), $parts),
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

        $generic = [
            'digərləri', 'sair', 'sair xidmətlər', 'digər',
            'others', 'other', 'other services',
            'прочие', 'прочее', 'прочие услуги', 'другие',
        ];
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
