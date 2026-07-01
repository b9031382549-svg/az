<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A reusable dictionary of uploaded item-name translations. Items are usually
// Azerbaijani (the base `source_text`); en/ru are produced by the app LLM at
// runtime and cached here, keyed by a normalized hash so the same item is never
// translated twice. Classifications reference a row by source_hash (the original
// source_text always stays the display fallback when a translation is missing).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_translations', function (Blueprint $table) {
            $table->id();
            $table->string('source_hash', 64)->unique(); // sha256 of normalized text
            $table->text('source_text');                 // a representative original
            $table->text('en')->nullable();
            $table->text('ru')->nullable();
            $table->timestamps();
        });

        Schema::table('classifications', function (Blueprint $table) {
            $table->string('source_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('classifications', function (Blueprint $table) {
            $table->dropColumn('source_hash');
        });

        Schema::dropIfExists('item_translations');
    }
};
