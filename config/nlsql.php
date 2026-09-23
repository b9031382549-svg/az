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
];
