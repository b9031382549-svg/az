<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Display translations of rubricator category titles (en/ru). Goods titles are
// derived from the already-translated catalog (name_en/name_ru); chapter and
// service titles come from the HsChapters / ServiceRubrics reference lists. The
// base `title` (Azerbaijani) stays the fallback.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rubricator_nodes', function (Blueprint $table) {
            $table->text('title_en')->nullable()->after('title');
            $table->text('title_ru')->nullable()->after('title_en');
        });
    }

    public function down(): void
    {
        Schema::table('rubricator_nodes', function (Blueprint $table) {
            $table->dropColumn(['title_en', 'title_ru']);
        });
    }
};
