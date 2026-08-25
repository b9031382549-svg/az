<?php

return [
    // Answer cache — the FIRST step of classification. Before any AI runs, the item's
    // name is looked up in the `answer_cache` table (verified name → 4-digit answer,
    // seeded from the Fedor reference). A hit resolves the item immediately, confident,
    // with NO LLM calls. A miss falls through to the mechanism pipeline. Currently an
    // exact normalized-name match; semantic (vector) lookup is planned.
    'cache' => [
        'enabled' => (bool) env('CLASSIFY_CACHE_ENABLED', true),
        // The one source a "reset memory" action wipes the production cache DOWN TO —
        // every other row (fedor / auto:consensus / confirmed / ai_resolved_grounded /
        // ...) is deleted. See AnswerCacheService::resetToBaseline().
        'baseline_source' => (string) env('CLASSIFY_CACHE_BASELINE_SOURCE', 'gold'),
    ],

    // Held-out benchmark/eval files — repo-relative paths, CSV (must have a 'name'
    // column) or .jsonl (must have a "name" field per line). `cache:seed
    // --exclude-benchmarks` never seeds a name found in any of these, so accuracy
    // measured against them always reflects real classification, never a cache hit.
    // Local research files (gitignored) — not present in every environment; a missing
    // file is a loud warning at seed time, never a silent partial exclusion.
    'held_out_benchmarks' => [
        '123/benchmark_frozen_296.csv',
        '123/benchmark_curated_452.csv',
        '123/benchmark_holdout_454.csv',
        'research-data/finetune/gold-split/test.jsonl',
    ],

    // Memory promotion — the write-back INVERSE of 'cache' above. When the ensemble
    // reaches a UNANIMOUS agreement (every authoritative mechanism that ran landed on the
    // same 4-digit heading), the item's answer is written back into the production
    // answer_cache (scope 0, source 'auto:consensus') so an identical name later resolves
    // for free with NO AI. ONLY unanimity is trusted: on the labelled benchmark a
    // unanimous agreement matched ~92-97%, a bare 2-of-3 majority only ~55% and the
    // web-search resolver ~63% — so a majority / ai_resolved answer is NEVER promoted (a
    // wrong row would be frozen and silently short-circuit the whole pipeline forever).
    // Rolled out behind 'shadow' first: shadow logs what it WOULD promote WITHOUT writing,
    // so the real-traffic volume/quality can be measured before it is switched live.
    'memory_promotion' => [
        'enabled' => (bool) env('CLASSIFY_MEMORY_PROMOTION', false),
        'shadow' => (bool) env('CLASSIFY_MEMORY_PROMOTION_SHADOW', true),
        // Minimum mechanisms that must BOTH run and agree for unanimity to count, so a
        // lone 1-of-1 (no independent corroboration) is never promoted.
        'min_agreement' => (int) env('CLASSIFY_MEMORY_PROMOTION_MIN_AGREEMENT', 2),
        // Provenance tag on written rows — distinguishes auto-promoted memory from the
        // seeded Fedor reference, so it stays auditable and revertable en masse
        // (cache:revert-promoted) if it ever proves to pollute.
        'source' => (string) env('CLASSIFY_MEMORY_PROMOTION_SOURCE', 'auto:consensus'),

        // Two more write-back paths into the SAME production memory, each independently
        // switchable. Both use insertOrIgnore-or-equivalent-safe writes; see
        // AnswerCacheService for the exact semantics of each.
        'confirmed' => [
            // A human explicitly confirmed/corrected an item — the single strongest trust
            // signal in the system. UPDATES an existing (possibly wrong) row: unlike the
            // automated paths, a human override is authoritative enough to overwrite.
            'enabled' => (bool) env('CLASSIFY_MEMORY_PROMOTE_CONFIRMED', false),
            'source' => (string) env('CLASSIFY_MEMORY_PROMOTE_CONFIRMED_SOURCE', 'confirmed'),
        ],
        'grounded_search' => [
            // A search-resolved (ai_resolved) answer, but ONLY when GROUNDED — see
            // Consensus::headingOverlaps() and search_resolver.grounded_min_confidence
            // below. Measured 93-96% real accuracy, comparable to the unanimous tier.
            'enabled' => (bool) env('CLASSIFY_MEMORY_PROMOTE_GROUNDED_SEARCH', false),
            'source' => (string) env('CLASSIFY_MEMORY_PROMOTE_GROUNDED_SEARCH_SOURCE', 'ai_resolved_grounded'),
        ],
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

    // The table the semantic (vector) search reads. 'catalog' is the stock index;
    // point it at a schema-qualified fine-tuned clone (e.g. 'ft.catalog') to A/B a
    // fine-tuned embedder WITHOUT overwriting the stock embeddings. On prod the
    // catalog is simply re-embedded in place, so this stays 'catalog'.
    'catalog_table' => (string) env('CLASSIFY_CATALOG_TABLE', 'catalog'),

    // Vector-first re-rank (for a STRONG fine-tuned embedder). Instead of the heavy
    // expansion + fusion + 2-tier pipeline (which was tuned to rescue the weak stock
    // vector and DEGRADES a strong one), hand the LLM the embedder's clean top-N and
    // let it pick, falling back to the nearest candidate when it abstains. Measured
    // (v2 fine-tune): full mechanism 66.6% vs vector-first 78.2% at the 4-digit heading.
    'vector_first' => [
        'enabled' => (bool) env('CLASSIFY_VECTOR_FIRST', false),
        'top_n' => (int) env('CLASSIFY_VECTOR_FIRST_TOP_N', 10),
        // Richer list: heading-diverse (≤1 code/heading → more distinct headings in
        // the same length, lifts the ceiling) + N lexical trigram matches (recover
        // items the vector misses on exact tokens). 0/false = plain top_n (78.2% base).
        'heading_diverse' => (bool) env('CLASSIFY_VF_HEADING_DIVERSE', false),
        'lexical' => (int) env('CLASSIFY_VF_LEXICAL', 0),
        // Show each candidate's cosine to the re-rank model (a "the vector prefers
        // this" signal). Gated so the default heavy-mechanism prompt is untouched.
        'show_sim' => (bool) env('CLASSIFY_VF_SHOW_SIM', false),
    ],

    // Independent search mechanisms run in parallel per item; their results are
    // stored side by side (classification_results) and reconciled into a
    // consensus. 'enabled' is the active set, in priority order. New mechanisms
    // are wired in AppServiceProvider's MechanismRegistry binding.
    'mechanisms' => [
        // Active mechanisms. The auto-resolve rule (Consensus::resolve) is DIRECT + vector
        // top-K membership, so only those two are authoritative by default.
        // BROKER is DISABLED 2026-08-21 (removed from this default) — it capped coverage on
        // the leak-free held-out set at a slim precision gain, and it is the heaviest
        // mechanism (brief + per-fork decide + leaf + fact). Its class + registry entry are
        // KEPT (App\Services\Classify\Mechanisms\BrokerDescentMechanism) so re-enabling is
        // just adding 'broker' back here (and reinstating the broker clause in resolve()).
        'enabled' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CLASSIFY_MECHANISMS', 'vector,direct')),
        ))),
        // Mechanisms that RUN and are stored but do NOT drive the consensus — for
        // measuring/calibrating a mechanism before it becomes authoritative. Empty by
        // default. (The broker is DISABLED entirely, not shadowed — it is absent from the
        // enabled set above; to measure it without letting it decide, add it to BOTH
        // CLASSIFY_MECHANISMS and CLASSIFY_SHADOW_MECHANISMS.)
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
        // Glossary grounding: before the brief, look the item's distinctive tokens up in
        // the catalog (the project's own AZ product dictionary) and inject the CLEAN
        // spelled term + kind-of-goods, so the brief stops misreading garbled AZ words
        // (BALGABAG→balqabaq/pumpkin). Deterministic catalog lookup, not vector retrieval.
        // Cached under a '-gloss' prompt-version suffix so it never masks base briefs. OFF
        // by default (measured marginal: +3 isolated identity, within noise).
        'brief_glossary' => (bool) env('CLASSIFY_BROKER_BRIEF_GLOSSARY', false),
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
        // Chapter shortlist: before the root descent, ONE model call proposes the N most
        // plausible chapters from the item + brief — the model's OWN HS knowledge, NOT
        // retrieval, so the broker stays independent of the vector mechanism. The root
        // fork then decides among those N with full cards; undecided → escape to the full
        // 97-chapter root. OFF by default (measured net-NEGATIVE at scale: −12 on 300 —
        // the ~11% recall miss cuts off the true chapter; kept flagged, do NOT enable).
        'chapter_shortlist' => (bool) env('CLASSIFY_BROKER_CHAPTER_SHORTLIST', false),
        'chapter_shortlist_n' => (int) env('CLASSIFY_BROKER_CHAPTER_SHORTLIST_N', 7),
        // '' → reuse broker.model for the shortlist call.
        'chapter_shortlist_model' => (string) env('CLASSIFY_BROKER_CHAPTER_SHORTLIST_MODEL', ''),
    ],

    // Vector (retrieval) mechanism. use_brief_query: seed retrieval with the shared
    // product brief's clean IDENTITY (e.g. "sweetened condensed milk") instead of only
    // the raw noisy text — so retrieval stops matching surface tokens ("с сахаром" →
    // sugar) and the right candidate reaches the shortlist. The brief is cached/shared
    // with the broker, so this costs no extra call.
    'vector' => [
        'use_brief_query' => (bool) env('CLASSIFY_VECTOR_USE_BRIEF_QUERY', true),
        // The vector no longer LLM-picks a single code — it returns its ranked shortlist,
        // and consensus/grounding test whether an answer is among its top-K candidates
        // (membership) rather than equal to one pick. K balances coverage vs precision on
        // the leak-free 2.2k held-out set: K=1 ≈ 96%/72%, K=3 ≈ 93.5%/82%, K=5 ≈ 92%/85%
        // (precision/coverage). K=3 is the chosen balance. Retrieval still fetches
        // vector_first.top_n candidates; only the first K gate agreement.
        'membership_k' => (int) env('CLASSIFY_VECTOR_MEMBERSHIP_K', 3),
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
        // Stricter bar for GROUNDED memory write-back (memory_promotion.grounded_search):
        // measured (3 prod test runs, ~900 pooled search-tier examples) — grounded +
        // confidence >= 0.98 => 93-96% real accuracy, comparable to the unanimous tier;
        // 0.98..0.90 only measured 80-87%. Kept at 0.90 anyway as a deliberate, permanent
        // choice (not the earlier 0.98->0.90 prod demo, which was meant to be reverted) —
        // now that Consensus::resolve() requires unanimity, non-unanimous conflicts are
        // both more common and inherently weaker evidence, so 0.90 trades some grounded-
        // write precision for materially higher recall on that larger conflict volume.
        'grounded_min_confidence' => (float) env('CLASSIFY_SEARCH_RESOLVER_GROUNDED_MIN_CONF', 0.90),
        'timeout' => (int) env('CLASSIFY_SEARCH_RESOLVER_TIMEOUT', 180), // web search + reasoning is slow
        'prompt_version' => (string) env('CLASSIFY_SEARCH_RESOLVER_VERSION', 's1'),
        // Cache confident web-search answers by (model, prompt_version, name) so an
        // identical item never pays for the slow `:online` call twice — shared by prod
        // and test runs. Only confident, catalog-valid answers are cached; a prompt_version
        // bump or `search-cache:clear` invalidates. See App\Services\Classify\SearchCache.
        'cache_enabled' => (bool) env('CLASSIFY_SEARCH_CACHE_ENABLED', true),
    ],
];
