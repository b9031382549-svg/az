<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One weekly retrain cycle. Progress fields (step/total_steps/loss/eta_seconds) are mirrored
// from the remote training heartbeat by the gpu:tick poller, so the UI shows a live bar with
// no long-running PHP job. See App\Services\Gpu\* and [[az-ab-gpu-orchestration]].
class FinetuneRun extends Model
{
    protected $guarded = ['id'];

    public const STATUS_PENDING = 'pending';

    public const STATUS_EXPORTING = 'exporting';

    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_TRAINING = 'training';

    public const STATUS_FETCHING = 'fetching';

    public const STATUS_EVALUATING = 'evaluating';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'corpus_rows' => 'integer',
            'corpus_sources' => 'array',
            'hyperparams' => 'array',
            'step' => 'integer',
            'total_steps' => 'integer',
            'loss' => 'float',
            'eta_seconds' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(GpuServer::class, 'gpu_server_id');
    }

    public function adapter(): BelongsTo
    {
        return $this->belongsTo(FinetuneAdapter::class, 'adapter_id');
    }

    /** The Testing dataset this run's corpus was scoped to, or null for the production corpus. */
    public function sourceDataset(): BelongsTo
    {
        return $this->belongsTo(TestDataset::class, 'source_dataset_id');
    }

    /** 0-100 training progress for the UI bar; null when total_steps is unknown yet. */
    public function progressPct(): ?int
    {
        if (! $this->total_steps || $this->total_steps <= 0) {
            return null;
        }

        return (int) min(100, round(100 * (int) $this->step / $this->total_steps));
    }
}
