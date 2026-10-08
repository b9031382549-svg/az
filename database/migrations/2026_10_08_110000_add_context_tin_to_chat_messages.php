<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The taxpayer (VÖEN) a chat turn was asked about — the chat's "this taxpayer" mode; null = the
// whole data. The history the model gets back keeps to the turns of the same context.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('context_tin', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('context_tin');
        });
    }
};
