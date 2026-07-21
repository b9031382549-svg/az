<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// One labelled row: the item name plus its correct answer. Scored at the 4-digit
// heading (goods) or the good/service flag. A row we could not parse a usable code
// for carries a skip_reason and is excluded from every accuracy denominator.
class TestDatasetRow extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expected_is_service' => 'boolean'];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(TestDataset::class, 'test_dataset_id');
    }

    /** @return HasMany<ClassificationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ClassificationItem::class, 'test_dataset_row_id');
    }

    public function isScorable(): bool
    {
        return $this->skip_reason === null;
    }
}
