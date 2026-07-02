# CLAUDE.md

Guidance for AI assistants (and humans) working in this repo. Claude Code reads
this automatically. Keep it accurate — update it when conventions change.

## What this is

**eInvoice AI** — tooling over Azerbaijani e-invoices. Two capabilities:

- **Task 1 — NL→SQL:** ask natural-language questions about invoices; an LLM
  writes **read-only** SQL (guarded + allow-listed) and runs it.
  `app/Services/NlSql`, `ai:ask`, `Livewire/AskAi`.
- **Task 2 — Classifier:** classify free-text goods/services line items to
  **XİF MN** codes (~11.6k catalog): good/service + code + confidence, with a
  review queue. `app/Services/Classify`, `classify:item`, `Livewire/Classify`.

The app is auth-gated (default login user `admin`).

## Stack

- PHP **8.3**, Laravel **13**, Livewire **4**, Vite + Tailwind **4**.
- **PostgreSQL + pgvector** (HNSW index) for vector search.
- **Redis + Laravel Horizon** for queues/workers.
- **Ollama** (`bge-m3`, 1024-dim) for local embeddings; **OpenRouter** for cloud LLM.
- Everything runs in **Docker** (dev and prod).

## Architecture map

- **Classify:** `ClassifierService` (single-shot) + a multi-mechanism ensemble in
  `Services/Classify/Mechanisms/` (`ClassifierMechanism` iface, `VectorMechanism`,
  `BrokerDescentMechanism`, `MechanismRegistry`, `MechanismResult`); `Consensus`
  merges mechanism outputs; `BrokerEvaluator`; `CatalogRetriever` (vector +
  lexical/synonyms); `ProductFactLookupService`. Jobs: `ClassifyMechanismJob`,
  `GenerateCatalogEmbeddings`. A **rubricator** tree (`RubricatorNode`,
  `data:build-rubricator`) backs the broker mechanism.
- **Embeddings:** `OllamaEmbedder` + `CatalogEmbeddingRunner` (resumable, batched
  job). HNSW index on `catalog.embedding`.
- **NL→SQL:** `NlSqlService`, `SchemaContext` (from `metadata_catalog`),
  `SqlGuard`/`SqlGuardException` (enforce read-only + table allow-list). A
  read-only DB role `app_ro` is used for the actual query (`nlsql:grant`).
- **LLM:** `OpenRouterClient`, `JsonExtractor`. Query-expansion model via
  `CLASSIFY_EXPAND_MODEL`. Every call is logged to `llm_usage`.
- **Translations:** `ItemTranslator`, `TranslateItems`/`TranslateItemJob`,
  `ItemTranslation`, `SetLocale` middleware (en/az/ru display).
- **Results API:** `routes/api.php` → `Api/ResultsApiController`, guarded by
  `ApiKeyAuth` (`RESULTS_API_KEY`) — read-only inspection of results + decision
  traces.
- **UI:** Livewire components (`Classify`, `ReviewQueue`, `ClassificationDecision`,
  `Invoices`, `AskAi`, `Catalog`, `UploadInvoices`, `Logs`, `ReportProblem`).

## Local development

- Start the stack: `docker compose up -d` (dev `docker-compose.yml` — bind-mounts
  the source, runs `php artisan serve`; separate `pgsql` + `ollama` services).
- All-in-one dev loop: `composer dev` (concurrently: serve, queue listener,
  `pail` logs, vite). First-time bootstrap: `composer setup`.
- Front-end: `npm run dev` (vite) locally, `npm run build` for assets. **Node 22**;
  `package-lock.json` is committed — use `npm ci`.
- Run artisan inside the container: `docker compose exec app php artisan …`.

## Key artisan commands

- `data:import-catalog` — import the XİF MN registry (`start-data/task 2/eqm_mal_kodlari-v1.xls`) → `catalog`.
- `data:embed-catalog [--queue|--refresh]` — bge-m3 embeddings (resumable via Horizon).
- `data:build-rubricator` — rebuild the rubricator tree (deterministic; required by the broker).
- `catalog:generate-synonyms` / `catalog:import-synonyms` — synonyms pipeline (re-embed after).
- `classify:item "<text>"` — classify one line item end-to-end.
- `classify:evaluate` / `classify:calibrate` / `classify:compare-retrieval` / `broker:eval` — quality tooling.
- `ai:ask "<question>"` — NL→SQL. `translate:items`, `lang:check`, `nlsql:grant`.

## Testing

- `php artisan test` (PHPUnit 12). Tests use **sqlite `:memory:`** (`phpunit.xml`) —
  no external services required.
- Keep tests green: CI runs them on every PR and on push to `main`, and a failing
  test **blocks the deploy**.

## Code style & conventions

- **Formatting: Laravel Pint** (`pint.json`, preset `laravel`). Run
  `./vendor/bin/pint` before committing (`--test` to check only). 4-space indent,
  LF, final newline (`.editorconfig`).
- **Typed code:** constructor property promotion with `private readonly`; typed
  params/returns; PHPDoc for array shapes (`@return array<string, mixed>`).
- **Comment the WHY, not the what** — short comments that explain intent/gotchas,
  as in the existing services.
- **Thin controllers/Livewire; logic in `app/Services/*`.** Read settings via
  `config()` (see `config/classify.php`, `config/nlsql.php`, `config/horizon.php`) —
  **not `env()` outside config files** (config is cached in prod).
- Background work goes through **Horizon (Redis)**, not `sync`.

## Git & deploy workflow (IMPORTANT)

- `main` is the **production branch**. Pushing/merging to `main` **auto-deploys to
  prod** (GitHub Actions → SSH → the server builds the image, runs migrations,
  rebuilds the rubricator, `optimize`s, restarts Horizon).
- Work on a **branch → open a PR → wait for green CI → merge**. Avoid pushing
  directly to `main` for non-trivial changes.
- Migrations run **once per deploy** (never from a container entrypoint). Changing
  `.env` on the server requires a redeploy / `php artisan optimize`.

## Env & secrets

- `.env` is **never committed** (`.env.prod.example` documents the prod keys). Real
  prod env lives only on the server; never bake secrets into images.
- Sessions/cache in Postgres; queue in Redis; OpenRouter key, Ollama URL, DB creds
  and `RESULTS_API_KEY` all come from env.

## Gotchas

- After changing catalog embedding logic or synonyms → re-embed with
  `data:embed-catalog --refresh`.
- Queue `retry_after` (`REDIS_QUEUE_RETRY_AFTER`) must exceed the longest job
  timeout, or jobs re-dispatch while still running (duplicate paid LLM calls).
- The catalog embed job self-chains in small batches — safe to interrupt/resume.
