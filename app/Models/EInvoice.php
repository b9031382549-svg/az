<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One invoice LINE. A legacy 15-column row is an invoice with a single line and no item
// detail; a line-level export ("Şablon") row also carries the item name, the supplier's
// declared code, unit and quantity, and links to the classification of that name.
class EInvoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'approval_date' => 'date',
            'excise_amount' => 'decimal:2',
            'vat_taxable_amount' => 'decimal:2',
            'non_vat_taxable_amount' => 'decimal:2',
            'vat_exempt_amount' => 'decimal:2',
            'zero_rated_vat_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'road_tax' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'quantity' => 'decimal:4',
        ];
    }

    public function classificationItem(): BelongsTo
    {
        return $this->belongsTo(ClassificationItem::class);
    }

    /**
     * Which of these invoice keys (series|number) are already in the table — optionally ignoring
     * the rows of one upload, so a big upload imported in portions never treats its own earlier
     * portions as duplicates.
     *
     * @param  array<int, string>  $keys
     * @return array<string, true>
     */
    public static function existingKeys(array $keys, ?string $exceptBatch = null): array
    {
        $known = [];
        foreach (array_chunk(array_values(array_unique($keys)), 1000) as $chunk) {
            $found = static::query()
                ->whereIn('invoice_key', $chunk)
                ->when($exceptBatch !== null, fn ($q) => $q->where(fn ($w) => $w
                    ->whereNull('import_batch')->orWhere('import_batch', '!=', $exceptBatch)))
                ->distinct()
                ->pluck('invoice_key');
            foreach ($found as $key) {
                $known[$key] = true;
            }
        }

        return $known;
    }
}
