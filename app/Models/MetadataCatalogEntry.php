<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetadataCatalogEntry extends Model
{
    protected $table = 'metadata_catalog';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'relationships' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
