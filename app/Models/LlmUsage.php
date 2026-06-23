<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlmUsage extends Model
{
    protected $table = 'llm_usage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }
}
