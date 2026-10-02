<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The units of measure the caller's lines carried for this name, as sent ("ədəd", "kq") —
// GET /api/classify/{id} returns them with the answer.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_request_names', function (Blueprint $table) {
            $table->json('units')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_request_names', function (Blueprint $table) {
            $table->dropColumn('units');
        });
    }
};
