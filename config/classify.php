<?php

return [
    // Answer cache — the FIRST step of classification. Before any AI runs, the item's
    // name is looked up in the `answer_cache` table (verified name → 4-digit answer,
    // seeded from the Fedor reference). A hit resolves the item immediately, confident,
    // with NO LLM calls. A miss falls through to the mechanism pipeline. Currently an
    // exact normalized-name match; semantic (vector) lookup is planned.
    'cache' => [
        'enabled' => (bool) env('CLASSIFY_CACHE_ENABLED', true),
    ],

    // Precedent-backed retrieval — a THIRD candidate source in CatalogRetriever,
    // alongside catalog-semantic and lexical. The nearest real-customs precedents
    // (product description → HS, translated to short Azerbaijani) vote by HS6
    // heading; the winning headings map to catalog candidate codes and fuse (RRF)
    // with the other sources. Grounded in how real products were actually
    // classified, complementing the catalog's legal definitions. OFF until the
    // `precedents` table is embedded and the accuracy gain is measured.
    'precedents' => [
        'enabled' => (bool) env('CLASSIFY_PRECEDENTS_ENABLED', false),
        'top_k' => (int) env('CLASSIFY_PRECEDENTS_TOP_K', 40),       // nearest precedents fetched per query
        'per_heading' => (int) env('CLASSIFY_PRECEDENTS_PER_HEADING', 4), // catalog codes expanded per winning HS6
    ],

    // Retrieval fusion. heading_fusion: fuse candidate evidence at the 4-DIGIT HS
    // HEADING level instead of the full code. Every source (semantic, lexical,
    // precedents) votes for a heading; RRF ranks headings; the shortlist is then
    // built heading-first (each heading's best codes, precedent-only headings pull a
    // nearest representative). Aggregates scattered per-code signal to the heading we
    // actually classify — measured +12pp recall@24 on the Fedor gold. OFF by default.
    'retrieval' => [
        'heading_fusion' => (bool) env('CLASSIFY_HEADING_FUSION', false),
        // Codes emitted per heading in the heading-first shortlist. 1 is best on the
        // Fedor gold (+10.7pp recall@24 vs +6pp at 2): a smaller cap fits MORE
        // headings into the shortlist, which is what the 4-digit classifier needs.
        'heading_codes' => (int) env('CLASSIFY_HEADING_CODES', 1),
    ],

    // Independent search mechanisms run in parallel per item; their results are
    // stored side by side (classification_results) and reconciled into a
    // consensus. 'enabled' is the active set, in priority order. New mechanisms
    // are wired in AppServiceProvider's MechanismRegistry binding.
    'mechanisms' => [
        'enabled' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CLASSIFY_MECHANISMS', 'vector,broker')),
        ))),
        // Mechanisms that RUN and are stored but do NOT drive the consensus — for
        // measuring/calibrating a mechanism before it becomes authoritative. Now
        // empty: the broker is AUTHORITATIVE (vector↔broker disagreement becomes a
        // conflict routed to a human). Re-shadow it with CLASSIFY_SHADOW_MECHANISMS=broker.
        'shadow' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CLASSIFY_SHADOW_MECHANISMS', '')),
        ))),
    ],

    // Broker-descent mechanism: walks the rubricator top-down, deciding each fork
    // by the sample leaves under its children (not the bare title). Strong model
    // only — the local tier is too weak for functional/GIR reasoning.
    'broker' => [
        'model' => (string) env('CLASSIFY_BROKER_MODEL', 'openai/gpt-4o'),
        'max_depth' => (int) env('CLASSIFY_BROKER_MAX_DEPTH', 5),
        'node_min_conf' => (float) env('CLASSIFY_BROKER_NODE_MIN_CONF', 0.6),
        'sample_leaves' => (int) env('CLASSIFY_BROKER_SAMPLE_LEAVES', 12),
        'leaf_direct_max' => (int) env('CLASSIFY_BROKER_LEAF_DIRECT_MAX', 20),
        'max_lookups' => (int) env('CLASSIFY_BROKER_MAX_LOOKUPS', 1),
        'fact_min' => (float) env('CLASSIFY_BROKER_FACT_MIN', 0.7),
        // Fact-acquisition model. The cheap default (gpt-4o-mini) is noisy on these
        // judgments — it guesses inconsistently (same question: "rubber 0.9" one
        // run, "plastic 0.5" the next). A strong model is better CALIBRATED: it
        // answers confidently when the fact is knowable and abstains (known=false)
        // when it genuinely is not, instead of guessing. It is one small call/item.
        'fact_model' => (string) env('CLASSIFY_BROKER_FACT_MODEL', 'openai/gpt-4o'),
        // A fork with more children than this (the 97-chapter root) is "wide":
        // branches carry a COMPACT card (scope + excludes) and fewer/shorter sample
        // leaves, so the prompt stays within the model's context window.
        'wide_fork' => (int) env('CLASSIFY_BROKER_WIDE_FORK', 20),
        'wide_sample_leaves' => (int) env('CLASSIFY_BROKER_WIDE_SAMPLE_LEAVES', 4),
        // Attach a branch's distilled legal card (COVERS/INCLUDES/EXCLUDES/CLOSED
        // LIST from hs_cards) at each fork, so the broker decides by the rulebook
        // rather than by sample leaves alone. A card is used only where one exists
        // for the branch; it informs the fork (the auto-confirm gate still applies)
        // rather than hard-overriding the decision.
        'use_cards' => (bool) env('CLASSIFY_BROKER_USE_CARDS', true),
        // Upfront "product brief": one strong-model call that UNDERSTANDS the item
        // (identity, purpose, composition) BEFORE the descent, replacing the broker's
        // noisy canonical essence with a clean description. It does NOT choose a
        // category — routing stays the cards' job. Two of its fields drive the review
        // gate below (decisive_axis + material.basis). Degrades to canonicalize()
        // essence on error/disabled, so it never blocks a classification.
        'use_brief' => (bool) env('CLASSIFY_BROKER_USE_BRIEF', true),
        'brief_model' => (string) env('CLASSIFY_BROKER_BRIEF_MODEL', 'openai/gpt-4o'),
        // (Disabled) The base brief could escalate to a WEB-SEARCH model for unfamiliar
        // brands. The flow no longer searches the web at the input — a blank model keeps
        // the brief to its single search-free pass. Set a `:online` model to re-enable.
        'brief_search_model' => (string) env('CLASSIFY_BROKER_BRIEF_SEARCH_MODEL', ''),
        'brief_search_below' => (float) env('CLASSIFY_BROKER_BRIEF_SEARCH_BELOW', 0.55),
        // Bump when the brief prompt changes materially — old cached briefs (keyed by
        // this version) are then ignored and re-generated instead of served stale.
        'brief_prompt_version' => (string) env('CLASSIFY_BROKER_BRIEF_VERSION', 'b5'),
        // Answer granularity. 'code' descends all the way to a full leaf code.
        // 'heading' stops at the deepest confident 4-digit heading — the top-down
        // descent already fixes the first 4 digits, so chasing a leaf only refines
        // digits 5-10 (which the 4-digit consensus discards) and can abstain when the
        // leaf/fallback fails. Stopping at the heading keeps those as correct votes.
        'answer_granularity' => (string) env('CLASSIFY_BROKER_ANSWER_GRANULARITY', 'code'),
    ],

    // Vector (retrieval) mechanism. use_brief_query: seed retrieval with the shared
    // product brief's clean IDENTITY (e.g. "sweetened condensed milk") instead of only
    // the raw noisy text — so retrieval stops matching surface tokens ("с сахаром" →
    // sugar) and the right candidate reaches the shortlist. The brief is cached/shared
    // with the broker, so this costs no extra call.
    'vector' => [
        'use_brief_query' => (bool) env('CLASSIFY_VECTOR_USE_BRIEF_QUERY', true),
    ],

    // Third, INDEPENDENT mechanism (App\Services\Classify\Mechanisms\DirectLlmMechanism):
    // a reasoning model that IDENTIFIES the item from its own knowledge, then codes it.
    // A different METHOD from retrieval/descent, so its vote is a genuinely independent
    // third opinion in the 2-of-3 heading consensus. No web search. Enable via
    // CLASSIFY_MECHANISMS.
    'direct' => [
        // A search-free reasoning model, deliberately a DIFFERENT family from the
        // DeepSeek broker/vector so its errors decorrelate for the 2-of-3 vote. No
        // `:online` suffix — this mechanism does NOT search the web.
        'model' => (string) env('CLASSIFY_DIRECT_MODEL', 'openai/gpt-oss-120b'),
        // Reasoning can be slow — this call gets a long HTTP timeout of its own.
        'timeout' => (int) env('CLASSIFY_DIRECT_TIMEOUT', 180),
        // Vote granularity. 'code' = recall a full 10-digit code and snap it to the
        // catalog (abstains when the recalled subheading has no row — a model cannot
        // memorise the ~11.6k national codes, so this abstains ~half the time).
        // 'heading' = recall only the 4-digit HS heading + good/service — far more
        // reliably recalled, all the 2-of-3 consensus needs, and lets it flag services.
        'granularity' => (string) env('CLASSIFY_DIRECT_GRANULARITY', 'code'),
    ],

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

    // Normalize a noisy item into a short canonical product description before
    // retrieval, so branded/coded/long names still find candidates.
    'expand_query' => (bool) env('CLASSIFY_EXPAND_QUERY', true),

    // Model for the expansion (query-normalization) step. This runs on EVERY
    // item, so it is a per-item cost. gpt-4o-mini mis-reads mixed AZ/RU noisy
    // names (garbled transliterations, wrong sense) — and the short canonical
    // name it produces is exactly what the vector search matches on — so
    // expansion defaults to the stronger model. Override per environment.
    'expand_model' => (string) env('CLASSIFY_EXPAND_MODEL', 'openai/gpt-4o'),

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

    // AI ADJUDICATOR: for DIVERGENT items (conflict / low-confidence review) a
    // reasoning-model arbiter is asked whether ONE code is UNAMBIGUOUSLY correct,
    // choosing only among the codes the mechanisms already surfaced. It can shed
    // human-review load without lowering accuracy — but only under guards: it
    // abstains by default, a stability re-sample must agree, and a random holdout
    // stays with humans so precision remains observable.
    //   mode=shadow  → judge + record only; the resolution is NOT changed (measure).
    //   mode=active  → a stable, confident, on-list verdict flips the item to
    //                  'ai_resolved' (a distinct, reversible, auditable state).
    // Disabled by default; the judge is gpt-oss-120b (a DIFFERENT model family from
    // the DeepSeek mechanisms, to decorrelate errors) WITH web search (`:online`), so
    // when the mechanisms diverge it can look the item up before ruling.
    'adjudicator' => [
        'enabled' => (bool) env('CLASSIFY_ADJUDICATOR_ENABLED', false),
        'mode' => (string) env('CLASSIFY_ADJUDICATOR_MODE', 'shadow'), // shadow | active
        'model' => (string) env('CLASSIFY_ADJUDICATOR_MODEL', 'openai/gpt-oss-120b:online'),
        'prompt_version' => (string) env('CLASSIFY_ADJUDICATOR_VERSION', 'j5'),
        // Resolutions the judge acts on. Abstention (a mechanism found no code) is
        // included but flagged (had_abstention) so it can be measured separately.
        'scope' => array_values(array_filter(array_map('trim', explode(',',
            (string) env('CLASSIFY_ADJUDICATOR_SCOPE', 'review,conflict'))))),
        // Stability: re-sample the judge; only a verdict whose winning code is the
        // same across all samples may auto-resolve (turns R1/LLM flakiness into a
        // usable disagreement signal instead of a self-reported boolean).
        'samples' => (int) env('CLASSIFY_ADJUDICATOR_SAMPLES', 2),
        'sample_temperature' => (float) env('CLASSIFY_ADJUDICATOR_SAMPLE_TEMP', 0.5),
        'min_confidence' => (float) env('CLASSIFY_ADJUDICATOR_MIN_CONF', 0.8),
        // Percent of judge-decidable items deliberately kept with humans (forever)
        // so auto-resolved precision stays observable. Deterministic per item.
        'holdout_pct' => (int) env('CLASSIFY_ADJUDICATOR_HOLDOUT_PCT', 10),
        'timeout' => (int) env('CLASSIFY_ADJUDICATOR_TIMEOUT', 90), // per judge call (s)
    ],

    // Search resolver — the LAST resort when the 3 mechanisms diverge (Consensus →
    // 'conflict'). A thinking model WITH web search (`:online`) IDENTIFIES the item
    // (looking up unfamiliar brands/drugs online), then returns just the 4-DIGIT HS
    // HEADING it belongs to plus a self-reported confidence. If it is confident enough
    // (>= min_confidence) and the heading is real, the item resolves to that heading
    // ('ai_resolved'); otherwise it stays 'conflict' for a human, with the search
    // attempt recorded as a trace. Fires once per conflict item (single-fire claim on
    // classification_items.search_resolved_at). Disabled by default — enable on prod.
    'search_resolver' => [
        'enabled' => (bool) env('CLASSIFY_SEARCH_RESOLVER_ENABLED', false),
        // A thinking DeepSeek with web search (the `:online` suffix = OpenRouter's web
        // plugin) — the same kind of call the old search-augmented direct used.
        'model' => (string) env('CLASSIFY_SEARCH_RESOLVER_MODEL', 'deepseek/deepseek-v4-flash:online'),
        // Confidence the model must self-report for its heading to be taken as correct.
        'min_confidence' => (float) env('CLASSIFY_SEARCH_RESOLVER_MIN_CONF', 0.8),
        'timeout' => (int) env('CLASSIFY_SEARCH_RESOLVER_TIMEOUT', 180), // web search + reasoning is slow
        'prompt_version' => (string) env('CLASSIFY_SEARCH_RESOLVER_VERSION', 's1'),
    ],
];
