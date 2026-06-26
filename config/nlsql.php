<?php

return [
    /*
    | The ONLY tables natural-language querying may see and touch. The AI's
    | schema context, the SqlGuard table allow-list and the read-only DB role's
    | grants are all derived from this list — so system tables (users, jobs,
    | sessions, cache, migrations, catalog, …) are never exposed.
    |
    | After changing this list, run: php artisan nlsql:grant
    */
    'tables' => [
        'e_invoices',
    ],
];
