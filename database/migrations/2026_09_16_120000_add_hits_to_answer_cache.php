<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How often an answer was served straight from memory (a cache hit) — powers the
// "Answered from memory N times" fact on the memory-position card. Incremented in
// AnswerCacheService::apply() for production hits only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('answer_cache', function (Blueprint $table) {
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('answer_cache', function (Blueprint $table) {
            $table->dropColumn(['hits', 'last_hit_at']);
        });
    }
};
