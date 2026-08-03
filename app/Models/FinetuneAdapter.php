<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A trained LoRA adapter. The durable master is the .tar at `vps_path` on the VPS; this
// row is its catalogue entry (provenance + measured accuracy). Rollback = point a GPU
// server at an earlier adapter — the rows never disappear. See [[az-ab-gpu-orchestration]].
class FinetuneAdapter extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'corpus_rows' => 'integer',
            'direct_acc' => 'integer',
            'overall_acc' => 'integer',
            'meta' => 'array',
        ];
    }
}
