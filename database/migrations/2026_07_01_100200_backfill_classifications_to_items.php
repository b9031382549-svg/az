<?php

use App\Models\Classification;
use App\Models\ClassificationItem;
use App\Models\ItemTranslation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// Migrate the legacy one-row-per-item `classifications` into the new parent/child
// model (classification_items + a 'vector' classification_results row each) so
// review history and exports survive the cutover. Idempotent; a no-op when the
// old table is empty (e.g. fresh sqlite test DB).
return new class extends Migration
{
    /** old status => parent resolution */
    private array $resolution = [
        'auto_confirmed' => 'agreed',
        'needs_review' => 'review',
        'confirmed' => 'confirmed',
        'rejected' => 'rejected',
        'no_match' => 'no_match',
        'error' => 'no_match',
    ];

    /** old status => per-mechanism result status */
    private array $resultStatus = [
        'auto_confirmed' => 'auto_confirmed',
        'needs_review' => 'needs_review',
        'confirmed' => 'auto_confirmed',
        'rejected' => 'needs_review',
        'no_match' => 'no_match',
        'error' => 'error',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('classifications')) {
            return;
        }

        $withCode = ['agreed', 'review', 'confirmed'];

        Classification::query()->orderBy('id')->chunkById(500, function ($rows) use ($withCode) {
            foreach ($rows as $c) {
                $resolution = $this->resolution[$c->status] ?? 'no_match';
                $hasCode = in_array($resolution, $withCode, true) && $c->matched_code;

                $item = ClassificationItem::firstOrCreate(
                    [
                        'batch' => $c->batch ?: 'legacy',
                        'source_hash' => $c->source_hash ?: ItemTranslation::hashFor((string) $c->source_text),
                    ],
                    [
                        'source_text' => $c->source_text,
                        'kind' => $c->kind,
                        'resolution' => $resolution,
                        'final_code' => $hasCode ? $c->matched_code : null,
                        'final_catalog_id' => $hasCode ? $c->catalog_id : null,
                        'created_at' => $c->created_at,
                        'updated_at' => $c->updated_at,
                    ],
                );

                $item->results()->updateOrCreate(
                    ['mechanism' => 'vector'],
                    [
                        'matched_code' => $c->matched_code,
                        'catalog_id' => $c->catalog_id,
                        'kind' => $c->kind,
                        'confidence' => $c->confidence,
                        'status' => $this->resultStatus[$c->status] ?? 'needs_review',
                        'candidates' => $c->candidates,
                        'explanation' => $c->explanation,
                    ],
                );
            }
        });
    }

    public function down(): void
    {
        // Irreversible data migration — the legacy classifications table is left
        // intact, so nothing is lost by not unwinding the backfill.
    }
};
