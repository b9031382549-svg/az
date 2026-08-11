<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which memory a run's corpus was exported from: null = production (answer_cache scope 0,
// the normal weekly retrain corpus); a positive id = ONE Testing dataset's own isolated
// memory (test_datasets.id) — an experiment/comparison run, never mixed with the
// production retrain cycle. See ExportFinetuneExamples --dataset and FinetuneManager::start().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finetune_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('source_dataset_id')->nullable()->after('gpu_server_id');
        });
    }

    public function down(): void
    {
        Schema::table('finetune_runs', function (Blueprint $table) {
            $table->dropColumn('source_dataset_id');
        });
    }
};
