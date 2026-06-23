<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Runs before every other migration (filename sorts first) so that later
// migrations may declare `vector` columns for catalog embeddings.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
