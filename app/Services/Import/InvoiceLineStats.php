<?php

namespace App\Services\Import;

use App\Models\ItemTranslation;

// Counts over line-level export lines, fed one line at a time — the same numbers whether a
// small file is previewed in the request or a big one is read in the background: how many
// lines can be tied to a specific invoice (and how many cannot), what is missing, and how many
// distinct item names go to classification.
final class InvoiceLineStats
{
    private int $lines = 0;

    private int $identified = 0;

    private int $noSupplierTin = 0;

    private int $noRecipientTin = 0;

    private int $noDate = 0;

    private int $noItem = 0;

    /** @var array<string, int> invoice_key => lines carrying it */
    private array $keys = [];

    /** @var array<string, true> a short hash of each normalised item name (memory: ~700k names fit) */
    private array $names = [];

    /** @param array<string, mixed> $line */
    public function add(array $line): void
    {
        $this->lines++;

        if (($key = $line['invoice_key'] ?? null) !== null) {
            $this->identified++;
            $this->keys[$key] = ($this->keys[$key] ?? 0) + 1;
        }
        if (($line['supplier_tin'] ?? null) === null) {
            $this->noSupplierTin++;
        }
        if (($line['recipient_tin'] ?? null) === null) {
            $this->noRecipientTin++;
        }
        if (($line['invoice_date'] ?? null) === null) {
            $this->noDate++;
        }
        if (($name = $line['item_name'] ?? null) === null) {
            $this->noItem++;
        } else {
            $this->names[hash('xxh64', ItemTranslation::normalizeName($name))] = true;
        }
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'lines' => $this->lines,
            'invoices' => count($this->keys),
            'identified_lines' => $this->identified,
            'unidentified_lines' => $this->lines - $this->identified,
            'no_supplier_tin' => $this->noSupplierTin,
            'no_recipient_tin' => $this->noRecipientTin,
            'no_date' => $this->noDate,
            'no_item' => $this->noItem,
            'unique_items' => count($this->names),
        ];
    }

    /** @return array<string, int> invoice_key => number of lines */
    public function keyCounts(): array
    {
        return $this->keys;
    }
}
