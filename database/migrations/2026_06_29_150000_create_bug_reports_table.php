<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// User-submitted problem reports from the "Report a problem" footer popup. Each
// carries the page's request_id so it can be traced against the audit / LLM logs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('request_id')->nullable()->index();
            $table->text('message');
            $table->text('url')->nullable(); // full URL incl. query string — can exceed 255
            $table->string('status', 16)->default('open')->index(); // open | resolved
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_reports');
    }
};
