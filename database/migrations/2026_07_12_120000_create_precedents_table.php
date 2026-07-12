<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Real customs (product → HS) precedents: one short canonical Azerbaijani product
// name per ruling, embedded (bge-m3) as a THIRD retrieval source fused into
// CatalogRetriever alongside catalog-semantic and lexical candidate generation.
// `hs6` bridges a matched precedent to catalog candidate codes; `desc` is kept for
// provenance/debugging (and as a supervised training set). `id` is the stable
// dataset id, so re-imports upsert in place.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('precedents', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary(); // stable dataset id (not auto-increment)
            $table->string('source', 16)->default('ebti'); // ebti | fcs
            $table->string('hs6', 6)->index();             // 6-digit HS — bridge to catalog codes
            $table->string('code');                        // full national code from the source ruling
            $table->string('lang', 8)->nullable();         // source language of desc
            $table->text('desc');                          // original customs description (provenance)
            $table->text('az');                            // short canonical Azerbaijani product name (embedded)
            $table->timestampTz('embedded_at')->nullable();
            $table->timestamps();
        });

        // pgvector is Postgres-only; the embedding column + HNSW index back semantic
        // retrieval, which is only exercised against Postgres. Skipped on sqlite (tests).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE precedents ADD COLUMN embedding vector(1024)');
            DB::statement('CREATE INDEX precedents_embedding_hnsw ON precedents USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('precedents');
    }
};
