<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One scored iteration over a dataset: run the classifier on every row and record
// per-mechanism accuracy. `mechanisms` snapshots the enabled+shadow set; `config`
// snapshots the FULL effective classify.* config (models AND retrieval flags) so a
// later "before/after" compares code changes, not silent config/model drift. Its
// classification_items live under batch = "testrun:{id}".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_dataset_id')->constrained('test_datasets')->cascadeOnDelete();
            $table->text('description');
            // "testrun:{id}" — set right after insert (it derives from the autoincrement id).
            $table->string('batch')->nullable()->index();
            $table->json('mechanisms');                       // {enabled:[...], shadow:[...], cache, search}
            $table->json('config');                           // snapshot of effective classify.* + models
            $table->string('status', 16)->default('pending')->index(); // pending|running|done|failed
            $table->unsignedInteger('total')->default(0);
            $table->json('accuracy')->nullable();             // {mechanism: {ran, correct}}
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_runs');
    }
};
