<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Two fixed inference slots, A and B, for the zero-downtime weekly-retrain ping-pong.
// One is `is_active` at a time (the endpoint the WHOLE system — prod classifier AND the
// testing tab — resolves a "gpu:" model to). The other is off, or (during a retrain
// window) training and about to become the new active one. When NEITHER is active the
// "gpu:" resolver falls back to Token Factory serving the BASE model (no fine-tune) — the
// UI labels that state. Nebius is only the current provider; on owned fixed servers we
// just fill a slot's connection columns by hand and never provision. See
// App\Services\Gpu\* and App\Services\Llm\InferenceEndpointResolver.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gpu_servers', function (Blueprint $table) {
            $table->id();
            $table->string('slot', 1)->unique();          // 'A' | 'B'
            $table->string('provider')->default('nebius'); // later 'static' for owned servers
            // 'serving' | 'training' | null (idle). Distinct from status: role is WHAT the
            // slot is for this cycle, status is WHERE it is in its lifecycle.
            $table->string('role')->nullable();
            // Lifecycle state machine advanced by the gpu:tick poller (never a long job):
            //   off → provisioning → booting → deploying_adapter → serving
            //   (training path) → training → uploading_adapter → ready_to_switch
            //   error (terminal until reset). See App\Services\Gpu\ServerStatus.
            $table->string('status')->default('off')->index();
            // The one flag the endpoint resolver reads. Exactly one row true (or none →
            // Token Factory fallback). Flipping it IS the zero-downtime switch.
            $table->boolean('is_active')->default(false)->index();
            // Live connection data returned by provisioning (or typed in for owned servers).
            $table->string('instance_id')->nullable();
            $table->string('ip')->nullable();
            $table->string('base_url')->nullable();        // OpenAI-compatible vLLM …/v1
            $table->string('api_key')->nullable();         // per-server generated key
            // Which adapter this slot's vLLM currently serves as `xif` (the fine-tuned
            // model). Plain reference (no FK constraint — mutual refs with finetune_runs).
            $table->unsignedBigInteger('served_adapter_id')->nullable()->index();
            $table->unsignedBigInteger('current_run_id')->nullable()->index();
            $table->text('status_detail')->nullable();     // last human-readable step/error
            // "Forgot" safety only (lives for Nebius): destroy a serving slot after 2h with
            // no request, or any slot past its hard 24h ceiling regardless of state.
            $table->timestamp('last_request_at')->nullable();
            $table->timestamp('hard_deadline_at')->nullable();
            $table->timestamps();
        });

        // Seed the two fixed slots, both off. Neither active → the system starts on the
        // Token Factory fallback exactly like today (no behaviour change until a slot is
        // provisioned and switched to).
        $now = now();
        DB::table('gpu_servers')->insert([
            ['slot' => 'A', 'provider' => 'nebius', 'status' => 'off', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now],
            ['slot' => 'B', 'provider' => 'nebius', 'status' => 'off', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('gpu_servers');
    }
};
