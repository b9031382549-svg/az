<?php

// The API for outside systems (routes/api.php). What a token may do: App\Support\ApiAbilities.
return [

    // Version of the API contract itself — its paths and fields.
    'version' => '1.0',

    // GET /api/version — which classifier is answering. `version` is OUR release of the
    // classifier as a whole: bump it whenever a model, threshold, prompt or rule that
    // changes answers changes. `trained_at` is the date of the newest model WE trained that
    // is in use (now sorter-v2, exported 2026-10-02). The third-party models are named in
    // the response's `components`, read live from the classify/services config.
    'model' => [
        'version' => '1.2.0',
        'trained_at' => '2026-10-02',
    ],

    // POST /api/classify — one request is one batch on the shared classification pipeline.
    'classify' => [
        'max_items' => 100000,
        // Same cap as an invoice line's item name (InvoiceLinesImporter).
        'max_name_length' => 5000,
        // GET /api/classify/{id} returns the answers a page at a time.
        'page_size' => 1000,
        'max_page_size' => 2000,
    ],

    // An answer's `similarity`: how often answers found the same way (DecisionSummary's
    // method) are right — our measured precision at the 4-digit heading, NOT a vector
    // cosine. Starting values, re-measure on held-out data after a model change:
    // consensus = Direct's heading in the vector top-3 (93.5% held-out); web search 93–96%
    // when grounded (its heading among the mechanisms' candidates and confident enough —
    // classify.search_resolver.grounded_min_confidence), 34–58% when not; trash from the
    // rules and the sorter ≈98%. ensemble, sorter (Direct + sorter agree on a service) and
    // ai are not measured yet — cautious guesses.
    'similarity' => [
        'human' => 0.99,
        'memory' => 0.97,
        'trash' => 0.98,
        'consensus' => 0.93,
        'sorter' => 0.9,
        'web_search' => 0.95,
        'web_search_ungrounded' => 0.45,
        'ensemble' => 0.7,
        'ai' => 0.5,
    ],

];
