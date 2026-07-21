<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A named, reusable set of test rows (item name + correct code) for measuring the
// classifier's accuracy from the UI. Its `mechanisms` default which tools a run
// exercises; each run is one scored iteration (see test_runs).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_datasets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // Default mechanism set for runs of this dataset:
            // {vector, broker, direct, cache:false, search:true}. A run snapshots its own.
            $table->json('mechanisms');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_datasets');
    }
};
