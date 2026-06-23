<?php

return [
    // How many fused candidates to hand the LLM re-ranker.
    'candidates' => (int) env('CLASSIFY_CANDIDATES', 24),

    // Confidence >= auto_confirm  -> auto_confirmed
    // Confidence >= review_floor  -> needs_review
    // otherwise                   -> needs_review (low confidence, flagged)
    'auto_confirm' => (float) env('CLASSIFY_AUTO_CONFIRM', 0.8),
    'review_floor' => (float) env('CLASSIFY_REVIEW_FLOOR', 0.5),
];
