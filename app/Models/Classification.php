<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Classification extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'candidates' => 'array',
            'confidence' => 'float',
        ];
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(CatalogCode::class, 'catalog_id');
    }
}
