<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A cached "product brief": the broker's upfront understanding of one item
// (identity, purpose, composition), reused across items/runs. See
// ProductBriefService. `data` holds the full brief the broker consumes.
class ProductBrief extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'ok' => 'boolean',
            'data' => 'array',
            'usage' => 'array',
        ];
    }
}
