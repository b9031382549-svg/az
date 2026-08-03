<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One retrain cycle: export corpus → provision/reuse a training slot → train from scratch
// (always from scratch on the cumulative answer_cache corpus, never incremental) → fetch
// the adapter to the VPS → eval-gate → (human) switch. Progress (step/loss/eta) is polled
// from the remote heartbeat file by gpu:tick and mirrored here so the UI shows a live bar
// without any long-running PHP job. See App\Services\Gpu\* and App\Livewire\GpuServers.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finetune_runs', function (Blueprint $table) {
            $table->id();
            // Which slot trains this run (plain reference, no FK — mutual with gpu_servers).
            $table->unsignedBigInteger('gpu_server_id')->nullable()->index();
            // Corpus snapshot: the exact JSONL and its composition, so a run is reproducible
            // and the UI can show what it was trained on.
            $table->string('corpus_path')->nullable();
            $table->unsignedInteger('corpus_rows')->nullable();
            $table->json('corpus_sources')->nullable();   // {source: count}
            $table->json('hyperparams')->nullable();       // {epochs, rank, lr, ...}
            // Lifecycle: pending → exporting → uploading → training → fetching → evaluating
            // → done | failed. Advanced by the poller / trigger jobs.
            $table->string('status')->default('pending')->index();
            // Live training progress mirrored from the remote heartbeat JSON.
            $table->unsignedInteger('step')->nullable();
            $table->unsignedInteger('total_steps')->nullable();
            $table->float('loss')->nullable();
            $table->unsignedInteger('eta_seconds')->nullable();
            // The adapter this run produced, and the TestRun used as its eval-gate.
            $table->unsignedBigInteger('adapter_id')->nullable()->index();
            $table->unsignedBigInteger('eval_test_run_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finetune_runs');
    }
};
