<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The rubricator: a separate, intermediate-node-only category tree the
// broker-descent mechanism navigates. Leaves are NOT stored here — they stay in
// `catalog` and are fetched by a node's code prefix at the final step. Built
// deterministically from catalog code prefixes; the main catalog is untouched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubricator_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('rubricator_nodes')->nullOnDelete();
            $table->unsignedTinyInteger('level');   // 1 chapter | 2 position | 3 subposition
            $table->string('code')->unique();       // 2 / 4 / 6-digit HS prefix
            $table->text('title')->nullable();      // az; null until derived or AI-generated
            $table->string('kind', 16)->index();    // good | service
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rubricator_nodes');
    }
};
