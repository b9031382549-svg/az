<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogCode extends Model
{
    protected $table = 'catalog';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'embedded_at' => 'datetime',
        ];
    }

    public function isService(): bool
    {
        return $this->kind === 'service';
    }
}
