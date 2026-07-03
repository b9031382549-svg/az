<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Cards" = the legal classification knowledge distilled once from the HS notes /
// Explanatory Notes, keyed by chapter/heading code. The broker attaches a code's
// card to its branch at a fork so it decides by the rulebook (what a heading
// COVERS / INCLUDES / EXCLUDES / its CLOSED LIST) instead of guessing from sample
// leaves. Separate table — the catalog and rubricator stay untouched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_cards', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // chapter (2) or heading (4) code
            $table->unsignedTinyInteger('level');       // 1 = chapter, 2 = heading
            $table->string('kind')->nullable();         // good / service
            $table->text('scope')->nullable();          // what the branch covers
            $table->json('includes')->nullable();       // [{product, syn:[...], note}]
            $table->json('excludes')->nullable();       // [{product_class, reroute_code, note}]
            $table->json('closed_list')->nullable();    // {exhaustive: bool, members: [...], note}
            $table->json('citations')->nullable();      // [note ids / refs]
            $table->string('source')->nullable();       // provenance
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_cards');
    }
};
