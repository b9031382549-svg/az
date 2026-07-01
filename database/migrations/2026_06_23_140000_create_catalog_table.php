<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// XİF MN (TN VED / HS-based) goods & services code registry. One row per
// 10-digit code. Carries the derived HS hierarchy, a good/service flag and a
// bge-m3 embedding for similarity search.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->text('name');                              // ADI (Azerbaijani description)
            $table->string('unit')->nullable();                // VAHID (unit of measure)
            $table->string('kind', 16)->index();               // good | service
            $table->string('chapter', 2)->nullable()->index();      // HS chapter  (2 digits)
            $table->string('position', 4)->nullable()->index();     // HS heading  (4 digits)
            $table->string('subposition', 6)->nullable()->index();  // HS subheading (6 digits)
            $table->boolean('is_active')->default(true);
            $table->timestampTz('embedded_at')->nullable();
            $table->timestamps();
        });

        // pgvector + pg_trgm are Postgres-only; skip on sqlite (tests). The
        // embedding column and trigram/HNSW indexes back retrieval, which is
        // only exercised against Postgres.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('ALTER TABLE catalog ADD COLUMN embedding vector(1024)');

            // Lexical candidate generation (brands, specs, exact tokens).
            DB::statement('CREATE INDEX catalog_name_trgm ON catalog USING gin (name gin_trgm_ops)');
            // Semantic candidate generation (cosine distance).
            DB::statement('CREATE INDEX catalog_embedding_hnsw ON catalog USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog');
    }
};
