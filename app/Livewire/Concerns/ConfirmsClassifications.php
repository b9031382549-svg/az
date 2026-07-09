<?php

namespace App\Livewire\Concerns;

use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Support\Audit;

/**
 * Shared confirm/reject logic for one classification item — used by the review queue
 * and the decision page so both apply the exact same rules.
 */
trait ConfirmsClassifications
{
    /**
     * Confirm an item with the chosen code. A 4-digit HS heading (or the bare "99"
     * service level) is confirmed at the heading — no exact catalog leaf; the item's own
     * answer is trusted, a correction to another heading must be a REAL heading. A full
     * 10-digit code must be one some mechanism actually considered. Returns true when applied.
     */
    protected function applyConfirm(ClassificationItem $item, string $code): bool
    {
        if (mb_strlen($code) < 10) {
            if ($code !== (string) $item->final_code) {
                $valid = $code === '99' || CatalogCode::where('position', $code)->where('is_active', true)->exists();
                if (! $valid) {
                    return false;
                }
            }
            $was = $item->final_code;
            $item->update([
                'resolution' => 'confirmed',
                'final_code' => $code,
                'final_catalog_id' => null,
                'kind' => $code === '99' ? 'service' : ($item->kind ?? 'good'),
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
            ]);
            Audit::log(((string) $was !== $code) ? 'classification.corrected' : 'classification.confirm',
                ['id' => $item->id, 'code' => $code, 'was' => $was], $item);

            return true;
        }

        if (! in_array($code, $item->allowedCodes(), true)) {
            return false;
        }
        $cand = CatalogCode::where('code', $code)->first();
        if (! $cand) {
            return false;
        }

        $was = $item->final_code;
        $item->update([
            'final_code' => $cand->code,
            'final_catalog_id' => $cand->id,
            'kind' => $cand->kind, // authoritative (99 => service)
            'resolution' => 'confirmed',
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now(),
        ]);
        Audit::log(((string) $was !== $code) ? 'classification.corrected' : 'classification.confirm',
            ['id' => $item->id, 'code' => $code, 'was' => $was], $item);

        return true;
    }

    protected function applyReject(ClassificationItem $item): void
    {
        $item->update(['resolution' => 'rejected']);
        Audit::log('classification.reject', ['id' => $item->id]);
    }
}
