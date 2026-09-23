<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// What the AI chat reads: every e_invoices row (one invoice LINE) with the classification of its
// item name and the upload it came from. A VIEW, so it is always current — a human confirming an
// item in Human review is visible to the chat at once — and the read-only chat role gets SELECT on
// this view alone (Postgres checks the underlying tables with the view OWNER's rights), never on
// classification_items / rubricator_nodes / import_batches themselves.
//
// Portable SQL (Postgres + sqlite for tests). NOTE: Postgres refuses to change the type of a
// column a view uses — a later migration altering those e_invoices columns must drop and
// recreate this view.
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
        DB::statement('DROP VIEW IF EXISTS invoice_lines');
    }
};
