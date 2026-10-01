<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// What KIND of line a dataset row is — good / service / trash — so a test run can score the
// sorter. A row may now carry only a kind (column B "TRASH" / "SERVICE" / "GOOD", no code):
// such a row is scored for the sorter alone, never for the code-picking mechanisms.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_dataset_rows', function (Blueprint $table) {
            $table->string('expected_type', 8)->nullable()->after('expected_is_service');
        });

        // Existing rows: a 99… code is a service, any other usable code a good.
        DB::table('test_dataset_rows')->whereNull('skip_reason')->where('expected_is_service', true)->update(['expected_type' => 'service']);
        DB::table('test_dataset_rows')->whereNull('skip_reason')->where('expected_is_service', false)->whereNotNull('expected_heading')->update(['expected_type' => 'good']);
    }

    public function down(): void
    {
        Schema::table('test_dataset_rows', function (Blueprint $table) {
            $table->dropColumn('expected_type');
        });
    }
};
