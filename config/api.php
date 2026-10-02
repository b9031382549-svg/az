<?php

// The API for outside systems (routes/api.php). What a token may do: App\Support\ApiAbilities.
return [

    // Version of the API contract itself — its paths and fields.
    'version' => '1.0',

    // GET /api/version — which classifier is answering. `version` is OUR release of the
    // classifier as a whole: bump it whenever a model, threshold, prompt or rule that
    // changes answers changes. `trained_at` is the date of the newest model WE trained that
    // is in use (now sorter-v1, exported 2026-10-01). The third-party models are named in
    // the response's `components`, read live from the classify/services config.
    'model' => [
        'version' => '1.0.0',
        'trained_at' => '2026-10-01',
    ],

    // POST /api/classify — one request is one batch on the shared classification pipeline.
    'classify' => [
        'max_items' => 100000,
        // Same cap as an invoice line's item name (InvoiceLinesImporter).
        'max_name_length' => 5000,
    ],

];
