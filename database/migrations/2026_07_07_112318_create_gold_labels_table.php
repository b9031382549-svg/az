<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reference ("gold") label for a product name, imported from an external
     * AI-labelled file. Two independent references live side by side (Ivan = full
     * 10-digit code; Fedor = 4-digit heading + good/service). We match our own
     * classifications to these by `name_key` and measure agreement — the reference
     * is a benchmark, not absolute truth.
     */
    public function up(): void
    {
        Schema::create('gold_labels', function (Blueprint $table) {
            $table->id();
            $table->string('source');                       // 'ivan' | 'fedor'
            $table->string('tier')->nullable();             // 'validated' | 'single' | ...
            $table->text('name');                           // raw product name from the file
            $table->string('name_key');                     // normalized key used for matching
            $table->string('code', 10)->nullable();         // full code (Ivan); null for Fedor
            $table->string('heading', 4)->nullable();       // 4-digit HS heading
            $table->string('chapter', 2)->nullable();       // 2-digit HS chapter
            $table->boolean('is_service')->nullable();      // service vs good, when known
            $table->float('confidence')->nullable();
            $table->string('unit')->nullable();
            $table->string('category')->nullable();
            $table->json('meta')->nullable();               // group, note, used_web, raw code, ...
            $table->timestamps();

            $table->unique(['source', 'name_key']);         // one label per name per reference
            $table->index('name_key');                      // matching against our items
            $table->index('heading');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gold_labels');
    }
};
