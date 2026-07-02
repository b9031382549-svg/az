<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A structured, human-readable trace of HOW a mechanism reached its answer
// (input -> essence -> queries/forks -> what it saw -> pick -> gate), so the
// "Decision" screen can show where a classification went right or wrong.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_results', function (Blueprint $table) {
            $table->json('trace')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('classification_results', function (Blueprint $table) {
            $table->dropColumn('trace');
        });
    }
};
