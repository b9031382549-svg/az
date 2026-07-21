<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One labelled row of a dataset: the item name plus its correct answer. We score
// at the 4-digit HS heading (or the good/service flag), so expected_heading /
// expected_is_service are derived from expected_code at import. A row we cannot
// parse a usable code for carries a skip_reason and is left out of the denominators.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_dataset_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_dataset_id')->constrained('test_datasets')->cascadeOnDelete();
            $table->text('source_text');
            $table->string('expected_code')->nullable();          // raw, zero-padded
            $table->string('expected_heading', 4)->nullable();     // first 4 digits
            $table->boolean('expected_is_service')->default(false);
            $table->string('skip_reason')->nullable();             // set => excluded from scoring
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_dataset_rows');
    }
};
