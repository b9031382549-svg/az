<?php

namespace App\Services\NlSql;

use App\Models\EInvoice;

/**
 * A taxpayer as the loaded invoice lines know it — for the chat's "this taxpayer" mode: is the
 * VÖEN in the data at all, under which name, and on how many lines it sells and buys. Looked up
 * here, on our side; only the counts go into the model's prompt.
 */
class Taxpayers
{
    /** @return array{tin: string, name: ?string, sold: int, bought: int}|null null when no line carries the VÖEN */
    public function find(string $tin): ?array
    {
        $tin = trim($tin);
        if (! preg_match(NlSqlService::TIN_FORMAT, $tin)) {
            return null;
        }

        $sold = EInvoice::where('supplier_tin', $tin)->count();
        $bought = EInvoice::where('recipient_tin', $tin)->count();
        if ($sold + $bought === 0) {
            return null;
        }

        $name = EInvoice::where('supplier_tin', $tin)->whereNotNull('supplier_name')->value('supplier_name')
            ?? EInvoice::where('recipient_tin', $tin)->whereNotNull('recipient_name')->value('recipient_name');

        return ['tin' => $tin, 'name' => $name, 'sold' => $sold, 'bought' => $bought];
    }
}
