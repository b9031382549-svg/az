<?php

namespace App\Services\Classify;

use App\Models\ClassificationItem;
use App\Models\EInvoice;

// What the invoices said about an item: the codes the supplier declared and the units on the
// invoice lines carrying its name. A hint for the HUMAN (review, Excel export) only — the declared code is
// the supplier's claim (often wrong on real exports), so it is never fed to the classifier.
class InvoiceLineHints
{
    /**
     * Null when no invoice line carries the item (typed or listed on the Classify page).
     *
     * @return array{lines: int, codes: array<int, array{code: string, group: ?string, lines: int}>, units: array<int, array{unit: string, lines: int}>}|null
     */
    public function for(ClassificationItem $item): ?array
    {
        $lines = $item->invoiceLines()->count();
        if ($lines === 0) {
            return null;
        }

        $codes = $item->invoiceLines()
            ->whereNotNull('declared_code')
            ->selectRaw('declared_code, max(declared_group) as declared_group, count(*) as c')
            ->groupBy('declared_code')
            ->orderByDesc('c')
            ->limit(3)
            ->get()
            ->map(fn ($r) => ['code' => (string) $r->declared_code, 'group' => $r->declared_group, 'lines' => (int) $r->c])
            ->all();

        $units = self::foldUnits($item->invoiceLines()
            ->whereNotNull('unit')
            ->selectRaw('unit, count(*) as c')
            ->groupBy('unit')
            ->get());

        return ['lines' => $lines, 'codes' => $codes, 'units' => $units];
    }

    /**
     * The units of many items at once (the Excel export — one query per chunk, not per item).
     * Items without invoice lines are absent.
     *
     * @param  iterable<int>  $itemIds
     * @return array<int, array<int, array{unit: string, lines: int}>> item id => units
     */
    public function unitsFor(iterable $itemIds): array
    {
        $out = [];
        // Chunked: an export can hold 20k items, past sqlite's / Postgres' bind-parameter caps.
        foreach (collect($itemIds)->unique()->chunk(5000) as $chunk) {
            EInvoice::query()
                ->whereIn('classification_item_id', $chunk->values())
                ->whereNotNull('unit')
                ->selectRaw('classification_item_id, unit, count(*) as c')
                ->groupBy('classification_item_id', 'unit')
                ->get()
                ->groupBy('classification_item_id')
                ->each(function ($rows, $id) use (&$out) {
                    $out[(int) $id] = self::foldUnits($rows);
                });
        }

        return $out;
    }

    /**
     * "ədəd ×12, kq ×3" — the counts only when the lines disagree on the unit.
     *
     * @param  array<int, array{unit: string, lines: int}>  $units
     */
    public static function formatUnits(array $units): string
    {
        return collect($units)
            ->map(fn ($u) => $u['unit'].(count($units) > 1 ? ' ×'.$u['lines'] : ''))
            ->implode(', ');
    }

    /**
     * Units come in any case (ədəd / ƏDƏD) — fold them in PHP (sqlite's lower() is ASCII-only);
     * the three most used.
     *
     * @param  iterable<int, object{unit: mixed, c: mixed}>  $rows
     * @return array<int, array{unit: string, lines: int}>
     */
    private static function foldUnits(iterable $rows): array
    {
        return collect($rows)
            ->groupBy(fn ($r) => mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], (string) $r->unit), 'UTF-8'))
            ->map(fn ($group, $unit) => ['unit' => (string) $unit, 'lines' => (int) $group->sum('c')])
            ->sortByDesc('lines')
            ->take(3)
            ->values()
            ->all();
    }
}
