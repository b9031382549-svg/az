<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Scope the answer cache by test dataset: 0 = PRODUCTION (the unbound cache the live
// classifier uses); a positive id binds the row to that dataset's test runs only. The
// one-answer-per-name uniqueness becomes one-per-(scope, name), so a dataset can hold
// its own memory without colliding with production. Sentinel 0 (not a nullable FK) so
// the existing composite upsert in SeedAnswerCache keeps working unchanged.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('answer_cache', function (Blueprint $table) {
            $table->unsignedBigInteger('test_dataset_id')->default(0)->after('id')->index();
            $table->dropUnique(['name_key']);              // was: one verified answer per name
            $table->unique(['test_dataset_id', 'name_key']); // now: one per (scope, name)
        });
    }

    public function down(): void
    {
        Schema::table('answer_cache', function (Blueprint $table) {
            $table->dropUnique(['test_dataset_id', 'name_key']);
            $table->unique('name_key');
            $table->dropIndex(['test_dataset_id']);
            $table->dropColumn('test_dataset_id');
        });
    }
};
