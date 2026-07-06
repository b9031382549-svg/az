<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One AI-adjudicator verdict on a divergent classification item. See
// AdjudicatorService. Kept for audit + offline precision measurement.
class ClassificationAdjudication extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'stable' => 'boolean',
            'had_abstention' => 'boolean',
            'applied' => 'boolean',
            'holdout' => 'boolean',
            'samples' => 'array',
            'usage' => 'array',
        ];
    }

    /** @return BelongsTo<ClassificationItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ClassificationItem::class, 'classification_item_id');
    }
}
