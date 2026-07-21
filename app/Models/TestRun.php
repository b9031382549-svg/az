<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// One scored iteration over a dataset. `mechanisms` snapshots the enabled+shadow set
// and the cache/search toggles; `config` snapshots the FULL effective classify.*
// config (models AND retrieval flags) so a later comparison reflects code changes,
// not silent config drift. Its scratch classification_items live under `batch`.
class TestRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'mechanisms' => 'array',
            'config' => 'array',
            'accuracy' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(TestDataset::class, 'test_dataset_id');
    }

    /** @return HasMany<ClassificationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ClassificationItem::class, 'test_run_id');
    }

    /** The batch key that scopes this run's scratch classification_items. */
    public static function batchKey(int $id): string
    {
        return "testrun:{$id}";
    }
}
