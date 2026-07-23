<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Optional per-run model/endpoint override for A/B-testing a candidate model served
// OUTSIDE prod (e.g. a fine-tuned LoRA on a rented GPU / self-hosted vLLM). NULL on a
// normal run → the run mirrors prod exactly (the subsystem's default — no drift). When
// set, ONLY this run's decision stages (rerank, broker, direct) are routed to the
// endpoint; expand + web search stay on prod. See App\Services\Testing\EndpointOverride.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            // e.g. "nebius:xif" — routed via OpenRouterClient's nebius: provider path.
            $table->string('model_override')->nullable()->after('config');
            // Optional SEPARATE model for query-expansion on the same endpoint. A
            // fine-tuned decision model (xif) can't expand (it only emits heading JSON),
            // so a full-GPU run sets decision=xif + expand=base. Blank → expand stays prod.
            $table->string('expand_model_override')->nullable()->after('model_override');
            // OpenAI-compatible base URL of the external endpoint (the rented GPU's
            // vLLM), overriding services.nebius.base_url for THIS run only.
            $table->string('endpoint_base_url')->nullable()->after('model_override');
            // API key for that endpoint (e.g. the vLLM --api-key). Low-sensitivity and
            // rotates with the rental; kept per-run so the dynamic IP + key travel together.
            $table->string('endpoint_api_key')->nullable()->after('endpoint_base_url');
        });
    }

    public function down(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            $table->dropColumn(['model_override', 'endpoint_base_url', 'endpoint_api_key']);
        });
    }
};
