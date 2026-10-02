<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per distinct name, exactly as an API caller sent it, in a POST /api/classify request
// (batch = import_batches.key). Classification dedups names case- and space-insensitively,
// so two spellings can share one item — the API still answers each spelling it was sent.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_names', function (Blueprint $table) {
            $table->id();
            $table->string('batch', 64)->index();
            $table->text('name');
            $table->foreignId('classification_item_id')->constrained('classification_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_names');
    }
};
