<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A cached answer to one product/brand question about one item, reused across
// items and mechanisms. See ProductFactLookupService.
class ProductFact extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'known' => 'boolean',
            'confidence' => 'float',
            'usage' => 'array',
        ];
    }
}
