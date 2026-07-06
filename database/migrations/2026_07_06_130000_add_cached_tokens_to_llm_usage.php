<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Records how many of a call's prompt tokens were served from the provider's
// prefix cache (DeepSeek/OpenAI). Lets us measure the real caching saving —
// cached input is billed at a fraction of fresh input.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_usage', function (Blueprint $table) {
            $table->unsignedInteger('cached_tokens')->default(0)->after('total_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('llm_usage', function (Blueprint $table) {
            $table->dropColumn('cached_tokens');
        });
    }
};
