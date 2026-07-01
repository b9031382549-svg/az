<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One uploaded line item (per batch) — the PARENT of the per-mechanism results.
// Several independent search mechanisms (vector, broker-descent, ...) each write
// a classification_results row; this row holds the resolved decision and the
// consensus state across them. Replaces the old one-row-per-item model.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_items', function (Blueprint $table) {
            $table->id();
            $table->string('batch');
            $table->text('source_text');
            $table->string('source_hash', 64)->index();     // links to item_translations
            $table->string('kind', 16)->nullable();          // good | service (final)
            $table->string('final_code')->nullable();        // resolved 10-digit code
            $table->foreignId('final_catalog_id')->nullable()->constrained('catalog')->nullOnDelete();
            $table->string('resolution', 24)->default('pending')->index();
            // pending | agreed | conflict | confirmed | no_match | blocked_on_fact
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            // Item-level idempotency: one parent per (batch, item). Also serves
            // WHERE batch = ? (leftmost), so no separate batch index is needed.
            $table->unique(['batch', 'source_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_items');
    }
};
