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

    public function code(): BelongsTo
    {
        return $this->belongsTo(CatalogCode::class, 'catalog_id');
    }
}
