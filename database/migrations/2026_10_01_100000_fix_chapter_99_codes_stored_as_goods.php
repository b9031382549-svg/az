<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Data fix: two paths stored a chapter-99 (service) heading as a GOOD — the ensemble resolver
// (kind = 'service' only for the bare "99") and a reviewer confirming a 4-digit heading (kind
// kept from the item). On prod that is 74 ensemble-resolved items + their trace rows, 1
// confirmed item and its memory row. Rule, as everywhere (HeadingMatch::isService): a code in
// chapter 99 is a service. Idempotent; the code fixes stop new ones.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('classification_items')
            ->where('final_code', 'like', '99%')
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', 'service'))
            ->update(['kind' => 'service']);

        DB::table('classification_results')
            ->where('mechanism', 'ensemble')
            ->where('matched_code', 'like', '99%')
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', 'service'))
            ->update(['kind' => 'service']);

        // Memory stores a service as is_service with no heading (AnswerCacheService).
        DB::table('answer_cache')
            ->where('heading', 'like', '99%')
            ->where('is_service', false)
            ->update(['is_service' => true, 'heading' => null]);
    }

    public function down(): void
    {
        // A correction of wrong labels — nothing to restore.
    }
};
