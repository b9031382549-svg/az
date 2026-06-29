<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One AI-chat turn (question -> answer) for the NL->SQL assistant, tied to the
// user who asked it, so chat history survives navigation and sign-out/in.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->text('answer')->nullable();        // conversational reply (no query)
            $table->text('sql')->nullable();           // generated read-only SQL, if any
            $table->text('explanation')->nullable();
            $table->json('columns')->nullable();       // result columns (capped view)
            $table->json('rows')->nullable();          // result rows (first 50)
            $table->boolean('truncated')->default(false);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);          // ordered per-user history fetch
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
