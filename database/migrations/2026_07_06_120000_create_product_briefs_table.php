<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cache of the upfront "product brief" — one strong-model call that UNDERSTANDS an
// item (identity, purpose, composition) before the broker descends the tree. Keyed
// by the item hash + the prompt version so a re-worded prompt re-briefs instead of
// serving a stale understanding. Broker-local: the vector mechanism never sees it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_briefs', function (Blueprint $table) {
            $table->id();
            $table->string('source_hash', 64);          // hash of the item text
            $table->string('prompt_version', 16);       // brief prompt version
            $table->text('identity')->nullable();       // what it fundamentally is + does
            $table->text('purpose')->nullable();        // what it is for / how used
            $table->string('function_class', 32)->nullable();
            $table->text('material_value')->nullable();   // free-text; text() so a verbose model value can't overflow varchar(255) and abort the insert
            $table->string('material_basis', 16)->nullable();   // stated | typical | unknown
            $table->string('decisive_axis', 16)->nullable();    // function | origin | material | identity
            $table->float('confidence')->nullable();
            $table->boolean('ok')->default(false);      // false = unusable (no identity / error)
            $table->json('data')->nullable();           // the full normalized brief the broker consumes
            $table->string('model')->nullable();
            $table->json('usage')->nullable();
            $table->timestamps();

            $table->unique(['source_hash', 'prompt_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_briefs');
    }
};
