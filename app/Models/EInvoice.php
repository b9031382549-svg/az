<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One invoice LINE. A legacy 15-column row is an invoice with a single line and no item
// detail; a line-level export ("Şablon") row also carries the item name, the supplier's
// declared code, unit and quantity, and links to the classification of that name.
class EInvoice extends Model
{
    /**
     * Where a line's classification stands — the chat's ai_status (invoice_lines view), as the
     * Invoices page filters by it and the line export writes it.
     */
    public const STATUSES = ['classified', 'needs_review', 'in_progress', 'trash', 'rejected'];

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
     * The Invoices page's filters, shared with the line export: a text search over the item, the
     * VÖENs and names, series, number and declared code; one upload; one classification status.
     */
    public function scopeFiltered(Builder $query, string $term = '', string $upload = '', string $status = ''): Builder
    {
        $term = trim($term);
        // ILIKE on Postgres, LIKE on sqlite (tests) — both case-insensitive for the search.
        $likeOp = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query
            ->when($upload !== '', fn (Builder $q) => $q->where('import_batch', $upload))
            ->when($term !== '', function (Builder $q) use ($term, $likeOp) {
                $like = '%'.$term.'%';
                $q->where(fn (Builder $w) => $w
                    ->where('supplier_tin', $likeOp, $like)
                    ->orWhere('recipient_tin', $likeOp, $like)
                    ->orWhere('number', $likeOp, $like)
                    ->orWhere('series', $likeOp, $like)
                    ->orWhere('item_name', $likeOp, $like)
                    ->orWhere('declared_code', $likeOp, $like)
                    ->orWhere('supplier_name', $likeOp, $like)
                    ->orWhere('recipient_name', $likeOp, $like));
            })
            ->when(in_array($status, self::STATUSES, true), fn (Builder $q) => $q->whereHas('classificationItem', function (Builder $item) use ($status) {
                match ($status) {
                    'classified' => $item->whereIn('resolution', ['agreed', 'ai_resolved', 'confirmed']),
                    // Still with the automation: not started yet, or a conflict the web search has not answered.
                    'in_progress' => $item->where(fn (Builder $w) => $w->where('resolution', 'pending')->orWhere(fn (Builder $r) => $r->resolving())),
                    // Everything the automation left open — the same ELSE the chat's view has.
                    'needs_review' => $item->whereNotIn('resolution', ['agreed', 'ai_resolved', 'confirmed', 'rejected', 'trash', 'pending'])
                        ->whereNot(fn (Builder $r) => $r->resolving()),
                    default => $item->where('resolution', $status),
                };
            }));
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
