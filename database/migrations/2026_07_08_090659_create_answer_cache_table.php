<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The answer cache: verified name → answer, looked up as the FIRST step of
     * classification. A hit short-circuits the whole AI pipeline (we already know the
     * answer, at 4-digit heading granularity). Seeded from the Fedor reference; grows
     * as we confirm more. `embedding` is reserved for later semantic lookup — for now
     * the lookup is an exact normalized-name match.
     */
    public function up(): void
    {
        Schema::create('answer_cache', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('fedor'); // provenance
            $table->text('name');                        // the raw reference name
            $table->string('name_key');                  // normalized key used for exact lookup
            $table->string('heading', 4)->nullable();    // the 4-digit answer (null for a service)
            $table->boolean('is_service')->default(false);
            $table->string('tier')->nullable();          // e.g. Fedor validated | claude
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('name_key');  // one verified answer per name
            $table->index('heading');
        });

        // Planned semantic lookup — Postgres/pgvector only; unused for now (exact
        // name_key match is step 1). Skipped on sqlite (tests).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE answer_cache ADD COLUMN embedding vector(1024)');
            DB::statement('CREATE INDEX answer_cache_embedding_hnsw ON answer_cache USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_cache');
    }
};
