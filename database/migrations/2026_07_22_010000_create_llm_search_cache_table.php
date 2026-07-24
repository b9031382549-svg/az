<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cache of confident web-search (`:online`) resolver answers, keyed by
// (model, prompt_version, source_hash) exactly like product_briefs — so an identical
// item name never pays for the slow paid search twice. Shared by prod + test runs.
// Only confident, catalog-valid answers are stored; invalidate by bumping
// search_resolver.prompt_version or `search-cache:clear`. See App\Services\Classify\SearchCache.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_search_cache', function (Blueprint $table) {
            $table->id();
            $table->string('model');
            $table->string('prompt_version', 32);
            $table->string('source_hash', 64);   // ItemTranslation::hashFor(item name)
            $table->json('response');             // {content, usage, model, annotations}
            $table->timestamps();
            // Same key shape as the live call → a duplicate write is a no-op (insertOrIgnore).
            $table->unique(['model', 'prompt_version', 'source_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_search_cache');
    }
};
