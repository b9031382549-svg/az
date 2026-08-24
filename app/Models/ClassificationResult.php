<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One search mechanism's answer for one item: the code it picked, its
// confidence/status, the candidate set it chose from, and (for broker-descent)
// the path[] trail. Aggregated into a consensus on the parent ClassificationItem.
class ClassificationResult extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'candidates' => 'array',
            'path' => 'array',
            'trace' => 'array',
            'usage' => 'array',
            'confidence' => 'float',
            'tier' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ClassificationItem::class, 'classification_item_id');
    }

    /**
     * The distinct 4-digit headings among this result's first $k candidates, in rank
     * order — the vector mechanism's "answer" under top-K membership (consensus and the
     * search resolver test membership in this set instead of equality with a single code).
     * Derived from the already-ordered `candidates` list; no duplicated storage.
     *
     * @return list<string>
     */
    public function topHeadings(?int $k = null): array
    {
        $k = max(1, (int) ($k ?? config('classify.vector.membership_k', 5)));

        $headings = [];
        foreach (array_slice((array) $this->candidates, 0, $k) as $c) {
            $code = is_array($c) ? ($c['code'] ?? null) : null;
            if ($code === null || $code === '') {
                continue;
            }
            $heading = mb_substr((string) $code, 0, 4);
            if (! in_array($heading, $headings, true)) {
                $headings[] = $heading;
            }
        }

        return $headings;
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(CatalogCode::class, 'catalog_id');
    }
}
