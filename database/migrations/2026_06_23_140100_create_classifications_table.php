<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One classified line item: good/service decision + matched catalog code,
// confidence and review status. Drives the review queue and overview charts.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classifications', function (Blueprint $table) {
            $table->id();
            $table->text('source_text');
            $table->string('kind', 16)->nullable();                  // good | service
            $table->foreignId('catalog_id')->nullable()->constrained('catalog')->nullOnDelete();
            $table->string('matched_code')->nullable();
            $table->float('confidence')->nullable();
            $table->string('status', 24)->default('needs_review')->index();
            // auto_confirmed | needs_review | confirmed | rejected | no_match
            $table->json('candidates')->nullable();                  // retrieval candidates + scores
            $table->text('explanation')->nullable();
            $table->string('batch')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classifications');
    }
};
