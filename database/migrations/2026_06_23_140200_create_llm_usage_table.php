<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Token accounting for external (OpenRouter) LLM calls — the "token-based
// processing mechanism" the two-tier design redirects to when the local model
// is insufficient.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_usage', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 32)->index();   // rerank | nlsql | gate
            $table->string('model');
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_usage');
    }
};
