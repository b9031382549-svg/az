<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One result from ONE search mechanism for ONE item. The parent
// classification_items row aggregates these into a consensus. The unique
// (classification_item_id, mechanism) index is the real idempotency guard —
// a retried job upserts the same row instead of duplicating it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classification_item_id')->constrained('classification_items')->cascadeOnDelete();
            $table->string('mechanism', 32);                 // vector | broker | ...
            $table->string('kind', 16)->nullable();          // good | service
            $table->foreignId('catalog_id')->nullable()->constrained('catalog')->nullOnDelete();
            $table->string('matched_code')->nullable();
            $table->float('confidence')->nullable();
            $table->string('status', 24)->default('needs_review');
            // auto_confirmed | needs_review | no_match | blocked_on_fact | error
            $table->json('candidates')->nullable();          // retrieval candidates + scores
            $table->json('path')->nullable();                // broker-descent trail
            $table->text('explanation')->nullable();
            $table->string('model')->nullable();             // LLM that produced the pick
            $table->unsignedTinyInteger('tier')->nullable(); // vector two-tier rerank tier
            $table->json('usage')->nullable();               // token usage for this result
            $table->timestamps();

            // Idempotency guard + indexes classification_item_id (leftmost).
            $table->unique(['classification_item_id', 'mechanism']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_results');
    }
};
