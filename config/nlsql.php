<?php

return [
    /*
    | The ONLY tables natural-language querying may see and touch. The AI's
    | schema context, the SqlGuard table allow-list and the read-only DB role's
    | grants are all derived from this list — so system tables (users, jobs,
    | sessions, cache, migrations, catalog, …) are never exposed.
    |
    | The deploy runs `php artisan nlsql:grant` to sync the role's grants with this
    | list (run it by hand after changing the list outside a deploy).
    */
    'tables' => [
        // A view over e_invoices (one row = one invoice line) plus the classification of
        // each line's item — see the create_invoice_lines_view migration.
        'invoice_lines',
    ],

    /*
    | The model that writes the chat's SQL. "gpu:base" = the stock Llama on our active GPU
    | server, or the Token Factory fallback model while none is serving
    | (InferenceEndpointResolver). A chat question rides the GPU while it is up but never
    | keeps it from idling down. Measured 2026-10-09: Llama ≈ gpt-4o-mini on the chat's SQL.
    */
    'model' => env('NLSQL_MODEL', 'gpu:base'),
];
