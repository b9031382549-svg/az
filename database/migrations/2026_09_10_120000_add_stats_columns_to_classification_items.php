<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Always-on per-batch classification stats need two facts the pipeline didn't record:
     *
     *  - answered_at: set ONCE the moment an item's automatic pipeline finishes for it —
     *    a cache hit, an agreed/no_match consensus, or the resolver giving its verdict
     *    (resolved OR handed to a human). It is NOT the same as updated_at, which a later
     *    human confirm/reject or a reaper re-run would move; this stays put, so the batch's
     *    real recognition time = max(answered_at) - min(created_at).
     *
     *  - memory_promoted_at: set the moment an item's answer is actually written back to the
     *    answer_cache (promote / promoteGroundedSearch / promoteConfirmed), so "sent to
     *    memory & training" counts what really happened, not a hypothetical would-promote.
     */
    public function up(): void
    {
        Schema::table('classification_items', function (Blueprint $table) {
            $table->timestamp('answered_at')->nullable()->after('search_resolved_at');
            $table->timestamp('memory_promoted_at')->nullable()->after('answered_at');
        });

        // Backfill answered_at for items that already finished before this column existed, so
        // the stats block reads sensibly for historical batches (otherwise every old, done
        // item counts as "still processing"). updated_at is an APPROXIMATION of the answer
        // time for these — good enough for old batches; new items get the exact timestamp at
        // the moment they resolve. memory_promoted_at is deliberately NOT backfilled: past
        // promotions are name-keyed in answer_cache and can't be reliably tied back to a row,
        // so historical batches show 0 "sent to memory" rather than a fabricated count.
        DB::table('classification_items')
            ->where('resolution', '!=', 'pending')
            ->whereNull('answered_at')
            ->update(['answered_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('classification_items', function (Blueprint $table) {
            $table->dropColumn(['answered_at', 'memory_promoted_at']);
        });
    }
};
