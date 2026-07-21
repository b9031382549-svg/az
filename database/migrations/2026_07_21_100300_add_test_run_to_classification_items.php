<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Link a classification_item produced by a dataset test run back to its run and the
// labelled row it answers. Both cascade on delete so removing a run (or its dataset)
// removes the scratch items + their results — NEVER left orphaned with a NULL
// test_run_id (that would re-surface them in the prod views that filter on NULL).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_items', function (Blueprint $table) {
            $table->foreignId('test_run_id')->nullable()->after('batch')
                ->constrained('test_runs')->cascadeOnDelete();
            $table->foreignId('test_dataset_row_id')->nullable()->after('test_run_id')
                ->constrained('test_dataset_rows')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('classification_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('test_run_id');
            $table->dropConstrainedForeignId('test_dataset_row_id');
        });
    }
};
