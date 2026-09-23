<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Invoice uploads (source = 'invoices') are tracked as import batches too, so one upload can
// be deleted on its own and a re-upload of the very same file is recognised.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('format', 16)->nullable();              // sablon | legacy (invoice uploads)
            $table->string('checksum', 64)->nullable()->index();   // sha256 of the uploaded file
            $table->json('stats')->nullable();                     // line / invoice-identity counts
            // Its e_invoices rows were deleted. The batch row itself stays while it still
            // labels a classification run in Review (the history is kept).
            $table->timestamp('lines_deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropIndex(['checksum']);
            $table->dropColumn(['format', 'checksum', 'stats', 'lines_deleted_at']);
        });
    }
};
