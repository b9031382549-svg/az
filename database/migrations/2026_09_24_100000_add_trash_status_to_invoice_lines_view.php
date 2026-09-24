<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The invoice_lines view again, with one more ai_status: 'trash' — the line's item name names no
// product (TrashFilter), so it is settled and never classified. Without it such a line fell into
// the ELSE branch and read as 'needs_review' (waiting for a human) to the chat.
//
// DROP + CREATE (portable to the sqlite tests); dropping the view drops the chat role's grant on
// it — the deploy re-runs nlsql:grant after migrating.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS invoice_lines');
        DB::statement(<<<'SQL'
            CREATE VIEW invoice_lines AS
            SELECT
                e.invoice_date,
                e.approval_date,
                e.invoice_type,
                e.series,
                e.number,
                e.invoice_key,
                e.supplier_tin,
                e.supplier_name,
                e.supplier_tax_office,
                e.recipient_tin,
                e.recipient_name,
                e.recipient_tax_office,
                e.item_name,
                e.unit,
                e.quantity,
                e.declared_code,
                substr(e.declared_code, 1, 4) AS declared_heading,
                e.declared_group,
                e.excise_amount,
                e.vat_taxable_amount,
                e.non_vat_taxable_amount,
                e.vat_exempt_amount,
                e.zero_rated_vat_amount,
                e.vat_amount,
                e.road_tax,
                e.total_amount,
                substr(ci.final_code, 1, 4) AS ai_code,
                ci.kind AS ai_kind,
                rn.title AS ai_heading_name,
                CASE
                    WHEN ci.id IS NULL THEN NULL
                    WHEN ci.resolution IN ('agreed', 'ai_resolved', 'confirmed') THEN 'classified'
                    WHEN ci.resolution = 'rejected' THEN 'rejected'
                    WHEN ci.resolution = 'trash' THEN 'trash'
                    WHEN ci.resolution = 'pending' THEN 'in_progress'
                    -- a conflict the web-search resolver has not finished yet (no 'search' trace)
                    WHEN ci.resolution = 'conflict' AND NOT EXISTS (
                        SELECT 1 FROM classification_results r
                        WHERE r.classification_item_id = ci.id AND r.mechanism = 'search'
                    ) THEN 'in_progress'
                    ELSE 'needs_review'
                END AS ai_status,
                ib.label AS upload_name,
                ib.created_at AS uploaded_at
            FROM e_invoices e
            LEFT JOIN classification_items ci ON ci.id = e.classification_item_id
            LEFT JOIN rubricator_nodes rn ON rn.code = substr(ci.final_code, 1, 4)
            LEFT JOIN import_batches ib ON CAST(ib.key AS TEXT) = e.import_batch
            SQL);
    }

    public function down(): void
    {
        // Back to the previous definition (the migration that created the view).
        (require __DIR__.'/2026_09_23_100200_create_invoice_lines_view.php')->up();
    }
};
