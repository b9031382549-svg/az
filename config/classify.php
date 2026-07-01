<?php

return [
    // How many fused candidates to hand the LLM re-ranker.
    'candidates' => (int) env('CLASSIFY_CANDIDATES', 24),

    // Universal retrieval: run two retrieval passes — one on the LLM-normalized
    // canonical query (clean head-noun) and one on the noise-stripped raw text —
    // and fuse them, so brand/barcode/flavour noise can't drown the real product.
    // Set false for the legacy single-combined-query behaviour.
    'multi_query' => (bool) env('CLASSIFY_MULTI_QUERY', true),

    // Legacy per-case disambiguation dictionary (traps). Off by default — the
    // universal multi_query retrieval generalises instead of hardcoding cases.
    // Kept only as an optional emergency safety net.
    'use_traps' => (bool) env('CLASSIFY_USE_TRAPS', false),

    // Two-tier re-ranking: a cheap/local-equivalent model (classify_model_tier1)
    // ranks first; if its pick is not confident AND semantically backed, the item
    // is escalated to the stronger fallback (classify_model). Set false to always
    // use the fallback model directly.
    'two_tier' => (bool) env('CLASSIFY_TWO_TIER', true),

    // Normalize a noisy item into a canonical product description (via the cheap
    // model) before retrieval, so branded/coded names still find candidates.
    'expand_query' => (bool) env('CLASSIFY_EXPAND_QUERY', true),

    // Translate uploaded item names (en/ru) for display, caching each in the
    // item_translations dictionary (translated once, reused everywhere). Display
    // always falls back to the original Azerbaijani text when a translation is
    // missing. Set false to skip translation entirely (originals only).
    'translate_items' => (bool) env('CLASSIFY_TRANSLATE_ITEMS', true),

    // Model used to translate item names. gpt-4o-mini handles Azerbaijani food/
    // nomenclature vocabulary poorly (hallucinates flavours, e.g. çiyələkli
    // "strawberry" -> "chocolate", and leaves words untranslated), so item
    // translation uses the stronger model by default.
    'translate_model' => (string) env('CLASSIFY_TRANSLATE_MODEL', 'openai/gpt-4o'),

    // Domain disambiguation map for Azerbaijani invoice traps: homonyms / false
    // friends / abbreviations whose sub-word matches the wrong category. When a
    // key (case-insensitive substring) is present, the hint is appended to the
    // retrieval text so the right sense is searched. Keep focused on confusions,
    // not general synonyms (those live in catalog.synonyms).
    'traps' => [
        'çay dəsmal' => 'mətbəx əl dəsmalı toxuculuq',   // tea TOWEL, not tea
        'cay desmal' => 'mətbəx əl dəsmalı toxuculuq',
        'çay dəsmalı' => 'mətbəx əl dəsmalı toxuculuq',
        'qrilyaj' => 'şirniyyat qrilyaj konfet',          // grillage sweet, not "grill"
        'midii' => 'midyə dəniz məhsulu',                 // mussels
        'midyə' => 'midyə dəniz məhsulu',
        'cath ' => 'kateter tibbi',                        // catheter abbreviation
        'kateter' => 'kateter tibbi alət',
        'desensitizer' => 'stomatoloji material',          // dental bonding agent
        'pancake' => 'xəmir məmulatı şirniyyat',
        'cib mendel' => 'kağız cib salfeti, kağızdan',     // pocket PAPER tissues (ch48)
        'cib mendil' => 'kağız cib salfeti, kağızdan',
        'soffione' => 'kağız salfet, kağızdan',            // paper-napkin brand (ch48)
    ],

    // Confidence >= auto_confirm  -> auto_confirmed
    // Confidence >= review_floor  -> needs_review
    // otherwise                   -> needs_review (low confidence, flagged)
    'auto_confirm' => (float) env('CLASSIFY_AUTO_CONFIRM', 0.8),
    'review_floor' => (float) env('CLASSIFY_REVIEW_FLOOR', 0.5),

    // Auto-confirm also requires the chosen code's semantic (cosine) similarity
    // to the item to clear this bar — so an over-confident LLM pick that retrieval
    // does not back gets routed to review instead of auto-confirmed.
    // Calibrated to 0.50 against the labelled sample: 0.60 left ~54% of CORRECT
    // confident picks needlessly in review (46% coverage); 0.50 lifts coverage to
    // ~75% at ~95% precision (see classify:calibrate).
    'min_semantic' => (float) env('CLASSIFY_MIN_SEMANTIC', 0.5),
];
