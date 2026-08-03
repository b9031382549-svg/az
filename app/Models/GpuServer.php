<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One of the two fixed inference slots (A / B). Exactly one row is `is_active` at a time —
// the endpoint every "gpu:" model resolves to. When none is active the resolver falls back
// to Token Factory serving the base model (no fine-tune). See InferenceEndpointResolver
// and the A/B GPU orchestration plan [[az-ab-gpu-orchestration]].
class GpuServer extends Model
{
    protected $guarded = ['id'];

    /** Lifecycle statuses (see the create migration's comment for the flow). */
    public const STATUS_OFF = 'off';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_BOOTING = 'booting';

    public const STATUS_DEPLOYING_ADAPTER = 'deploying_adapter';

    public const STATUS_SERVING = 'serving';

    public const STATUS_TRAINING = 'training';

    public const STATUS_UPLOADING_ADAPTER = 'uploading_adapter';

    public const STATUS_READY_TO_SWITCH = 'ready_to_switch';

    public const STATUS_ERROR = 'error';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_request_at' => 'datetime',
            'hard_deadline_at' => 'datetime',
        ];
    }

    /** The adapter this slot's vLLM currently serves as the fine-tuned model. */
    public function adapter(): BelongsTo
    {
        return $this->belongsTo(FinetuneAdapter::class, 'served_adapter_id');
    }

    /** The currently-active slot, or null → the resolver falls back to Token Factory. */
    public static function active(): ?self
    {
        return static::query()->where('is_active', true)->first();
    }

    /**
     * Human-friendly, translatable status label for the UI (the raw enum value like
     * "deploying_adapter" is confusing — especially for a base serve where no adapter is
     * involved). Pass the return through __() in the view.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OFF => 'Off',
            self::STATUS_PROVISIONING => 'Provisioning…',
            self::STATUS_BOOTING => 'Booting…',
            self::STATUS_DEPLOYING_ADAPTER => 'Loading model…',
            self::STATUS_SERVING => 'Serving',
            self::STATUS_TRAINING => 'Training…',
            self::STATUS_UPLOADING_ADAPTER => 'Uploading adapter…',
            self::STATUS_READY_TO_SWITCH => 'Ready to switch',
            self::STATUS_ERROR => 'Error',
            default => $this->status,
        };
    }

    /**
     * What this slot serves: the fine-tuned adapter version, or "base" while loading/serving
     * the stock model (no adapter). Null when off — the caller shows "—". Clears up the
     * "adapter: —" confusion for a base serve.
     */
    public function servedModel(): ?string
    {
        if ($this->adapter) {
            return $this->adapter->version;
        }
        if (in_array($this->status, [self::STATUS_DEPLOYING_ADAPTER, self::STATUS_SERVING], true)) {
            return 'base';
        }

        return null;
    }

    /** True when this slot can actually take inference traffic right now. */
    public function isServing(): bool
    {
        return $this->status === self::STATUS_SERVING && filled($this->base_url);
    }

    /**
     * Map a logical "gpu:" model name to the concrete model this server's vLLM serves.
     * 'tuned' → the fine-tuned adapter (served as `xif`); 'base' → the stock model
     * (served as `base`); anything else is passed through verbatim (escape hatch).
     */
    public function modelFor(string $logical): string
    {
        return match ($logical) {
            'tuned' => 'xif',
            'base' => 'base',
            default => $logical,
        };
    }

    /**
     * Mark that this slot just took a request — feeds the 2h idle autostop. THROTTLED to
     * at most one write per minute (a bare column update, not a full model save) so it is
     * negligible on the classifier's hot path. Only meaningful while Nebius is rented.
     */
    public function markRequested(): void
    {
        if ($this->last_request_at !== null && $this->last_request_at->gt(now()->subMinute())) {
            return;
        }
        $now = now();
        static::query()->whereKey($this->getKey())->update(['last_request_at' => $now]);
        $this->last_request_at = $now;
    }
}
