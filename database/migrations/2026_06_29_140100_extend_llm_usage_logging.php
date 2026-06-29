<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Turn token accounting (llm_usage) into a full per-call decision log: latency,
// tier, status/error, the request correlation id, and (behind a config flag)
// the full prompt + response — so classifier / NL->SQL decisions can be audited
// and evaluated.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_usage', function (Blueprint $table) {
            $table->string('tier', 16)->nullable()->after('purpose');     // tier1 | tier2 | null
            $table->string('status', 16)->nullable()->after('model');     // ok | error
            $table->unsignedInteger('latency_ms')->nullable()->after('total_tokens');
            $table->text('error')->nullable();
            $table->text('prompt')->nullable();                           // full request (flagged)
            $table->text('response')->nullable();                         // full response (flagged)
            $table->uuid('request_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('llm_usage', function (Blueprint $table) {
            $table->dropColumn(['tier', 'status', 'latency_ms', 'error', 'prompt', 'response', 'request_id']);
        });
    }
};
