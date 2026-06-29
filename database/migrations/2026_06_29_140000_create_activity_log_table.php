<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Immutable audit trail of meaningful user actions (who did what, to what, with
// which inputs/outputs), correlated to the HTTP request via request_id so a bug
// report can be traced end-to-end.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();          // e.g. classification.confirm
            $table->nullableMorphs('subject');          // affected entity (optional)
            $table->json('properties')->nullable();     // inputs / outputs / context
            $table->string('ip', 45)->nullable();
            $table->uuid('request_id')->nullable()->index();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
