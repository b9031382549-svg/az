<?php

namespace App\Jobs;

use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Services\Classify\AdjudicatorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the AI adjudicator for ONE divergent item and, in active mode, applies a
 * stable+confident verdict by flipping the item to 'ai_resolved'. Dispatched once
 * per item by Consensus::finalize() (guarded by the adjudicated_at atomic claim).
 * Shadow mode records the verdict without touching the resolution.
 */
class AdjudicateItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300; // reasoning-model calls × stability samples

    public function __construct(public int $itemId) {}

    public function handle(AdjudicatorService $adjudicator): void
    {
        $cfg = (array) config('classify.adjudicator');
        if (! ($cfg['enabled'] ?? false)) {
            return;
        }

        $item = ClassificationItem::find($this->itemId);
        if ($item === null) {
            return;
        }

        // Re-check scope against fresh state: a human may have decided meanwhile.
        $scope = (array) ($cfg['scope'] ?? ['review', 'conflict']);
        if (! in_array($item->resolution, $scope, true)) {
            return;
        }

        $adj = $adjudicator->run($item);
        if ($adj === null || ($cfg['mode'] ?? 'shadow') !== 'active') {
            return; // shadow: recorded only, resolution untouched
        }

        $decidable = $adj->verdict === 'resolved' && $adj->stable && $adj->winning_code !== null
            && (float) $adj->confidence >= (float) ($cfg['min_confidence'] ?? 0.8);
        if (! $decidable) {
            return;
        }

        // Deterministic holdout — keep a slice with humans forever so the
        // auto-resolved population's precision stays observable.
        if ($this->inHoldout($item, (int) ($cfg['holdout_pct'] ?? 10))) {
            $adj->update(['holdout' => true]);

            return;
        }

        // A heading-level verdict is a bare 4-digit code: there is no exact catalog row,
        // so store the heading itself (final_catalog_id stays null) with the judge's kind.
        // A full 10-digit verdict must map to a real catalog code.
        if (mb_strlen((string) $adj->winning_code) === 4) {
            $update = ['final_code' => (string) $adj->winning_code, 'final_catalog_id' => null, 'kind' => $adj->winning_kind];
        } else {
            $cat = CatalogCode::where('code', $adj->winning_code)->first();
            if ($cat === null) {
                return;
            }
            $update = ['final_code' => $cat->code, 'final_catalog_id' => $cat->id, 'kind' => $cat->kind];
        }

        // Conditional update — never clobber a human/terminal decision that landed
        // while this job was queued. Only a still-divergent item flips.
        $changed = ClassificationItem::whereKey($item->id)
            ->whereIn('resolution', ['review', 'conflict'])
            ->update(['resolution' => 'ai_resolved'] + $update);

        if ($changed === 1) {
            $adj->update(['applied' => true]);
        }
    }

    /** Stable per-item holdout membership (not random per run, so it is idempotent). */
    private function inHoldout(ClassificationItem $item, int $pct): bool
    {
        if ($pct <= 0) {
            return false;
        }
        if ($pct >= 100) {
            return true;
        }
        $seed = (string) ($item->source_hash ?? $item->id);

        return (hexdec(substr(hash('crc32b', $seed), 0, 6)) % 100) < $pct;
    }
}
