<?php

return [
    // How many fused candidates to hand the LLM re-ranker.
    'candidates' => (int) env('CLASSIFY_CANDIDATES', 24),

    // Confidence >= auto_confirm  -> auto_confirmed
    // Confidence >= review_floor  -> needs_review
    // otherwise                   -> needs_review (low confidence, flagged)
    'auto_confirm' => (float) env('CLASSIFY_AUTO_CONFIRM', 0.8),
    'review_floor' => (float) env('CLASSIFY_REVIEW_FLOOR', 0.5),

    // Auto-confirm also requires the chosen code's semantic (cosine) similarity
    // to the item to clear this bar — so an over-confident LLM pick that retrieval
    // does not back gets routed to review instead of auto-confirmed.
    'min_semantic' => (float) env('CLASSIFY_MIN_SEMANTIC', 0.6),
];
