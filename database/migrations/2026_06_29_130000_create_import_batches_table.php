<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One classification upload ("batch"): a file import or a manual form entry,
// with a human-readable label, so the review queue can separate uploads instead
// of mixing every item together. Keyed by the same UUID stored on
// classifications.batch.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('key')->unique();                 // == classifications.batch
            $table->string('label');                       // filename or "Manual entry"
            $table->string('source', 16)->default('file'); // file | manual
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
