<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A named, reusable set of labelled test rows for measuring classifier accuracy
// from the UI. `mechanisms` holds the default tool set a run of this dataset uses.
class TestDataset extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mechanisms' => 'array'];
    }

    protected static function booted(): void
    {
        // Bound answer_cache rows are scoped by an integer sentinel (not an FK), so clean
        // them up here when the dataset is deleted (rows/runs cascade via real FKs).
        static::deleting(function (self $dataset) {
            AnswerCache::where('test_dataset_id', $dataset->id)->delete();
        });
    }

    /** @return HasMany<TestDatasetRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(TestDatasetRow::class);
    }

    /** @return HasMany<TestRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(TestRun::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Rows that carry a usable expected answer (not skipped) — the scoring universe. */
    public function scorableRows(): HasMany
    {
        return $this->rows()->whereNull('skip_reason');
    }
}
