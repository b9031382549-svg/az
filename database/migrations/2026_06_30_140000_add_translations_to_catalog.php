<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Display translations of the classifier catalog names (English / Russian). The
// Azerbaijani `name` stays the base used for retrieval/embeddings; these are
// shown in the UI per the active locale only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog', function (Blueprint $table) {
            $table->text('name_en')->nullable();
            $table->text('name_ru')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('catalog', function (Blueprint $table) {
            $table->dropColumn(['name_en', 'name_ru']);
        });
    }
};
