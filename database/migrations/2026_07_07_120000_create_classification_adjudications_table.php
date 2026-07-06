<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The AI adjudicator's verdict on ONE divergent item (conflict / low-confidence
// review): a reasoning-model arbiter deciding whether one code is unambiguously
// correct. Written on EVERY judged item (resolved or uncertain) for audit +
// offline measurement, whether or not it was allowed to change the resolution.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_adjudications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classification_item_id')->constrained()->cascadeOnDelete();
            $table->string('resolution_before', 24);   // conflict | review at judgment time
            $table->string('model');
            $table->string('prompt_version', 16);
            $table->string('verdict', 16);             // resolved | uncertain | error
            $table->string('winning_code', 16)->nullable();
            $table->string('winning_kind', 16)->nullable();
            $table->float('confidence')->nullable();
            $table->string('which_mechanism', 16)->nullable(); // broker | vector | both | neither
            $table->boolean('stable')->default(false); // stability samples agreed
            $table->boolean('had_abstention')->default(false); // one mechanism abstained
            $table->text('rule_basis')->nullable();    // the cited card clause / GIR rule
            $table->text('reason')->nullable();
            $table->string('mode', 12);                // shadow | active
            $table->boolean('applied')->default(false);   // actually changed the item to ai_resolved
            $table->boolean('holdout')->default(false);   // routed to human on purpose (monitoring)
            $table->json('samples')->nullable();       // per-sample {verdict, code} for stability
            $table->json('usage')->nullable();
            $table->timestamps();

            $table->unique(['classification_item_id', 'prompt_version']);
        });

        Schema::table('classification_items', function (Blueprint $table) {
            // Atomic claim so the adjudicator dispatches exactly once — finalize()
            // runs on every mechanism completion AND on the failed() path.
            $table->timestamp('adjudicated_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('classification_items', function (Blueprint $table) {
            $table->dropColumn('adjudicated_at');
        });
        Schema::dropIfExists('classification_adjudications');
    }
};
