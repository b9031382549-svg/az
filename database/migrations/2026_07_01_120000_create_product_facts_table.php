<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cache of one-off product/brand facts the broker acquires to resolve a fork
// ("is this a plastic article or a medical device?"). Keyed by the item hash +
// the question hash so the same fact is fetched once and reused everywhere.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_facts', function (Blueprint $table) {
            $table->id();
            $table->string('source_hash', 64);       // hash of the item text
            $table->string('criterion_hash', 64);    // hash of the question
            $table->text('fact')->nullable();
            $table->boolean('known')->default(false); // false = model didn't know / low confidence
            $table->float('confidence')->nullable();
            $table->string('source', 16)->default('model'); // model | human
            $table->string('model')->nullable();
            $table->json('usage')->nullable();
            $table->timestamps();

            $table->unique(['source_hash', 'criterion_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_facts');
    }
};
