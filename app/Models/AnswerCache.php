<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A verified answer for a product name — the classification cache. Looked up FIRST
 * (before any AI); a hit returns a 4-digit heading (or a service flag) we are
 * confident in. Seeded from the Fedor reference; `embedding` is reserved for future
 * semantic lookup. See App\Services\Classify\AnswerCacheService.
 */
class AnswerCache extends Model
{
    protected $table = 'answer_cache';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_service' => 'boolean',
            'meta' => 'array',
        ];
    }

    /** Same normalization as the gold labels, so keys match across the two. */
    public static function keyFor(string $name): string
    {
        return GoldLabel::keyFor($name);
    }
}
