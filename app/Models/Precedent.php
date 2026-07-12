<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A real customs (product → HS) precedent with a short canonical Azerbaijani product
 * name. Embedded (bge-m3) and fused as a third source into CatalogRetriever; `hs6`
 * bridges a matched precedent to catalog candidate codes. `id` is the stable dataset
 * id (not auto-increment). See App\Services\Classify\CatalogRetriever.
 */
class Precedent extends Model
{
    protected $table = 'precedents';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'embedded_at' => 'datetime',
        ];
    }
}
