<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Registry of every LoRA adapter we have trained. The durable master of each adapter
// lives on the VPS (vps_path) — NOT in this DB and NOT baked into the golden GPU image —
// so a rollback is just "point the active GPU server at an earlier adapter row". Each row
// records where it came from (corpus + run) and its measured eval-gate accuracy, so the
// UI can show "new adapter 86.5% vs current 82.9%" before a switch. See the A/B GPU
// orchestration plan (App\Services\Gpu, App\Livewire\GpuServers).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finetune_adapters', function (Blueprint $table) {
            $table->id();
            // Human-stable label, e.g. "2026-07-30-a" — shown in the UI and used as the
            // filename on the VPS. Unique so the same version can't be registered twice.
            $table->string('version')->unique();
            // Absolute path of the durable .tar on the VPS (the asset that survives a
            // deploy, mirrors research-data/finetune adapters). Null until fetched.
            $table->string('vps_path')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('base_model')->default('meta-llama/Llama-3.3-70B-Instruct');
            // Provenance: which exported corpus + which run produced this adapter.
            $table->string('corpus_path')->nullable();
            $table->unsignedInteger('corpus_rows')->nullable();
            $table->unsignedBigInteger('trained_from_run_id')->nullable()->index();
            // Eval-gate result (Direct-stage + overall heading accuracy on the held-out
            // dataset), populated by the eval TestRun. Null until measured.
            $table->unsignedTinyInteger('direct_acc')->nullable();   // percent 0-100
            $table->unsignedTinyInteger('overall_acc')->nullable();  // percent 0-100
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finetune_adapters');
    }
};
