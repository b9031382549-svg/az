<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records a meaningful user action to the durable audit trail (activity_log)
 * and mirrors it to the application log. Logging must never break the action,
 * so every failure is swallowed.
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(string $action, array $properties = [], ?Model $subject = null): void
    {
        $requestId = app()->bound('request_id') ? app('request_id') : null;

        try {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => $action,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'properties' => $properties ?: null,
                'ip' => request()?->ip(),
                'request_id' => $requestId,
                'created_at' => now(),
            ]);

            Log::info('audit:'.$action, ['request_id' => $requestId] + $properties);
        } catch (Throwable $e) {
            Log::warning('audit log failed for '.$action.': '.$e->getMessage());
        }
    }
}
