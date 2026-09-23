<?php

namespace App\Services\Classify;

use App\Models\ClassificationItem;

// What the invoices said about an item: the codes the supplier declared and the units on the
// invoice lines carrying its name. A hint for the HUMAN reviewer only — the declared code is
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

        // Units come in any case (ədəd / ƏDƏD) — fold them in PHP (sqlite's lower() is ASCII-only).
        $units = $item->invoiceLines()
            ->whereNotNull('unit')
            ->selectRaw('unit, count(*) as c')
            ->groupBy('unit')
            ->get()
            ->groupBy(fn ($r) => mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], (string) $r->unit), 'UTF-8'))
            ->map(fn ($group, $unit) => ['unit' => (string) $unit, 'lines' => (int) $group->sum('c')])
            ->sortByDesc('lines')
            ->take(3)
            ->values()
            ->all();

        return ['lines' => $lines, 'codes' => $codes, 'units' => $units];
    }
}
