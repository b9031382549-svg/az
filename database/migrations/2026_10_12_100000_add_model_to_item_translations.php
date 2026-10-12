<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which model wrote a translation (as the provider names it: `base` = Llama on our GPU).
// A translation is kept forever, so knowing who made it is what lets a better route redo
// one model's rows (`items:translate --redo-model=…`) instead of the whole dictionary.
// Rows from before this column stay null — gpt-4o / DeepSeek, never the GPU.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_translations', function (Blueprint $table) {
            $table->string('model')->nullable()->after('ru');
        });
    }

    public function down(): void
    {
        Schema::table('item_translations', function (Blueprint $table) {
            $table->dropColumn('model');
        });
    }
};
