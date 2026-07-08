<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-fire claim for the post-consensus SEARCH RESOLVER. Consensus::finalize
     * runs once per mechanism (3+ times/item), so it atomically claims this timestamp
     * (whereNull -> update) before dispatching SearchResolveJob — the affected-row count
     * guarantees exactly one dispatch. Kept distinct from `adjudicated_at` (the dormant
     * judge's claim, still read by the review UI).
     */
    public function up(): void
    {
        Schema::table('classification_items', function (Blueprint $table) {
            $table->timestamp('search_resolved_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('classification_items', function (Blueprint $table) {
            $table->dropColumn('search_resolved_at');
        });
    }
};
