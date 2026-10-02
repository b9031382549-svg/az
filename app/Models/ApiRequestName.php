<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A distinct name exactly as an API caller sent it, and the classification item answering it.
class ApiRequestName extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['units' => 'array'];
    }

    /** @return BelongsTo<ClassificationItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ClassificationItem::class, 'classification_item_id');
    }
}
