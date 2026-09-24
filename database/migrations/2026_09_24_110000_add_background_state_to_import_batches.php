<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// State of a BIG invoice upload, read and imported in the background (BackgroundInvoiceUploads):
// analyzing → ready (preview) → importing → imported, or failed. Null for every upload handled
// inside the request (all uploads before this, and small files after it).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('status', 16)->nullable()->index();
            // Working state: the stored file, lines read so far, the preview, import progress,
            // the final report, an error. Only the job driving the current phase writes it.
            $table->json('meta')->nullable();
            // Classification feeding cursor: items up to this id have been put on the queue.
            // Its own column so the feeder can claim a portion atomically (compare-and-set).
            $table->unsignedBigInteger('fed_until_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'meta', 'fed_until_id']);
        });
    }
};
