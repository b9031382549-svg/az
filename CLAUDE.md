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
- **Trash filter:** `TrashFilter` — rules (no AI), run by `ClassificationQueue`
  right after the answer cache: a name that names no product (only a document
  reference / date / period, digits, a plate, an e-mail, a bare company name, a
  person's name = known first name from `FirstNames` + surname) → resolution
  `trash`, no mechanism jobs. Conservative by design (~98% precision, catches ~20%
  of human-labelled trash). Only digits is absolute (beats the cache, no override);
  otherwise a reviewer can send it back ("Not trash — classify" on the decision
  page → trace row `overridden`, never re-trashed).
- **Sorter:** a small model (XLM-R, fine-tuned on lines labelled by people) that says
  good / service / trash for a line — served by the `sorter` container
  (`docker/sorter/server.py`, ONNX int8 on CPU) and called by `SorterClient`. After the
  cache and the TrashFilter rules, `ClassificationQueue` puts the remaining names on
  `SortItemsJob` (queued, never inline): each item gets a `sorter` trace row; p(trash) ≥
  `classify.sorter.trash_threshold` → `trash` (rule `sorter`, same override as the rules).
  `Consensus::resolve`: Direct "service" + sorter "service" → `agreed` at 99 (the vector
  ranks goods and cannot back a service). Fail-open: service down → pipeline as before.
  Testing tab: a run takes prod's path — only-digits → memory (the dataset's own) → the
  TrashFilter rules → `SortTestItemsJob` (sure trash taken out; the rest enlisted into the
  run's batch for the AI mechanisms) → mechanisms. Every row also gets a sorter verdict → a
  "Sorter" column scored on the kind of line (`RunScorer`: kind accuracy, trash P/R alone
  and with the rules, service P/R, confusion) and a "Trash filter" funnel step (removed /
  right / caught). "Overall" counts a coded row the trash step took out as a miss. A
  dataset's column B may name only the kind — TRASH / SERVICE / GOOD
  (`test_dataset_rows.expected_type`); such rows run the same pipeline and are scored in
  "Overall" by the kind they ended as, never in the code columns.
- **Web-search trash check** (`classify.search_resolver.trash_check`, ON since 2026-10-05): the conflict
  resolver's web-grounded understanding step (`SearchResolverService::understand`, needs
  `flow.ensemble_resolver`) may also answer "names no product" (person / company / institution /
  document / address …, prompt kept word for word as measured in
  `research-data/trash-late-stage-2026-10-05`). With the sorter's p(trash) ≥ `min_sorter_trash`
  the item becomes `trash` (rule `search`, same "not trash" override), no code is forced on it and
  no further paid call is made; off → the old prompt, byte for byte. A Testing run has its own
  switch (`mechanisms.trash_check`, the "Web search: no product named" box), and its funnel shows
  what the search caught apart from the rules + sorter step.
- **Embeddings:** `OllamaEmbedder` + `CatalogEmbeddingRunner` (resumable, batched
  job). HNSW index on `catalog.embedding`.
- **Uploads — one door for files:** `InvoiceUploads` (the Upload page's one entry
  point; the Classify page keeps only its text box and links here; recognises the
  layout from the header) → `InvoiceLinesImporter` for the line-level export
  "Şablon" (format `sablon`) AND a list of item names (format `names`: a "Malın
  adı" column or one of `NAME_HEADERS`, or a single column under no known header —
  then its first row is a name too): every line → `e_invoices`, every unique item
  name → classification via `ClassificationQueue`, the shared path with the
  Classify page; or `InvoiceImporter` (legacy 15-column invoice list, by position).
  No item-name column and < 15 columns → refused with a message pointing to the
  template (`/upload/template`, `UploadTemplateController`: the Şablon header row,
  only "Malın adı" required). Small and big CSVs share `SheetStream`'s separator
  rules — a one-column list keeps the commas in its names ("PIVƏ 1,0 LT PET").
  `e_invoices` is one row per LINE (legacy rows = whole invoices, `item_name`
  NULL; names-list rows = the name only, no date — lists sort `NULLS LAST`);
  `invoice_key` = series|number (NULL = line not tied to an invoice). Each upload
  is an `ImportBatch` (source `invoices`). The supplier's `declared_code` is
  stored only — never fed to the classifier or to memory.
- **Big uploads** (.xlsx/.csv above `uploads.background_bytes`, up to 200 MB —
  `config/uploads.php`): same steps (preview → Import) but on the worker via
  `BackgroundInvoiceUploads` (status on `import_batches`: analyzing → ready →
  importing → imported / failed; the page polls). `SheetStream` (OpenSpout)
  reads in one pass → NDJSON; `ImportInvoiceUploadJob` imports it in portions
  from a saved offset; `FeedUploadClassificationJob` feeds the names to the
  single queue in small portions (Redis 512 MB); `TendInvoiceUploadsJob` (every
  5 min) resumes/prunes. Files live in the `uploads` volume shared by app +
  worker (`storage/app/uploads`).
- **NL→SQL:** `NlSqlService`, `SchemaContext` (from `metadata_catalog`),
  `SqlGuard`/`SqlGuardException` (enforce read-only + table allow-list). The chat
  reads only the `invoice_lines` VIEW (e_invoices + each line's classification +
  upload). A read-only DB role `app_ro` is used for the actual query
  (`nlsql:grant`).
- **LLM:** `OpenRouterClient`, `JsonExtractor`. Query-expansion model via
  `CLASSIFY_EXPAND_MODEL`. Every call is logged to `llm_usage`.
- **Translations:** `ItemTranslator`, `TranslateItems`/`TranslateItemJob`,
  `ItemTranslation`, `SetLocale` middleware (en/az/ru display).
- **API tokens:** Laravel Sanctum, token-only (`config/sanctum.php` guard `[]` — no
  session auth on the API). Issued and revoked on Settings → API tokens (`ApiTokens`),
  shown once; abilities in `App\Support\ApiAbilities`. Every `/api` route needs
  `Authorization: Bearer <token>` plus its ability (missing → 403).
- **Results API:** `routes/api.php` → `Api/ResultsApiController` (ability `results`) —
  read-only inspection of results + decision traces.
- **Classifier API** (the client's integration; they host it; client docs in Russian:
  `API.md`, and the in-app page `/api-docs` (`PageController::apiDocs` →
  `pages/api-docs.blade.php`, limits/fields/similarity/version read live from config) —
  keep both in sync with the endpoints): `GET /api/version` (any
  token) = our release (`config/api.php` — bump `model.version` when answers can change)
  + the models the chain is configured to use (`ModelVersion`). `GET /api/health-check`
  (any token) = `HealthCheck`: liveness only, each probe instant and free (DB, Redis,
  Horizon workers, sorter /health, Ollama has the embed model, one HNSW lookup, the
  answer-deciding LLMs still listed by their providers — cached 5 min). It never
  classifies or embeds: a busy service must not read as down, since the host's monitoring
  may restart on a 500. A `gpu:` model is looked up with `touch: false` so probes never
  keep a GPU slot from idling down.
  `POST /api/classify` (ability `classify`, ≤100k items, only `name` required; any
  Şablon field may ride along under its `e_invoices` column name) =
  `ClassifyRequests`: one request = one `ImportBatch` (source `api`). The request only
  validates, saves the body to the uploads volume and answers 202 + `request_id`;
  `IngestApiRequestJob` (worker) creates the items and hands the batch to the shared
  feeding chain (`BackgroundInvoiceUploads::feed`, status `imported`). `api_request_names`
  keeps every distinct name exactly as sent (TrimStrings/ConvertEmptyStringsToNull are
  skipped for this route) → its item + the units of measure its lines carried. Lines
  with invoice fields go to `e_invoices` through `InvoiceLinesImporter::writeLines`
  (`lineFromFields`), exactly like a Şablon upload — invoices another upload loaded are
  skipped, their names still answered; name-only lines write no invoice row. `GET /api/classify/{id}`: accepted → processing →
  done (every item has `answered_at`) | failed, plus `classified_items` a page at a time
  (`offset`/`limit`) from `ClassifyAnswers`: per name `category` (final_code; null for
  trash / no answer), `kind`, `units`, `status` (pending / answered / needs_review), `method` +
  `reason` (`DecisionSummary`) and `similarity` = measured precision of that method
  (`config/api.php` `similarity`; a web-search answer is split grounded / ungrounded by
  the same bar memory promotion uses). Not a vector cosine.
- **Method + reason:** `DecisionSummary` — per item, which step found the code
  (memory / trash / Direct+vector / ensemble / web search / human) and why, in the
  UI language (model quotes stay English). Feeds the review Excel export
  (`ReviewExportController` → `ClassificationExporter`) and the Results API.
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
- The deploy also re-runs `nlsql:grant` (chat role grants ← `config/nlsql.php`)
  and `MetadataCatalogSeeder` (chat column descriptions) — both idempotent.

## Env & secrets

- `.env` is **never committed** (`.env.prod.example` documents the prod keys). Real
  prod env lives only on the server; never bake secrets into images.
- Sessions/cache in Postgres; queue in Redis; OpenRouter key, Ollama URL and DB creds
  all come from env. API tokens live in the DB (`personal_access_tokens`, hashed).

## Gotchas

- The sorter's model files are NOT in git: prod `/opt/az-assets/sorter-v2` (model.onnx +
  tokenizer.json + meta.json with its sha256; v1 kept in `/opt/az-assets/sorter`), local
  `research-data/sorter-model/v2` (same sha). `classify.sorter.trash_threshold` and
  `classify.sorter.model` belong to THAT file — change them together (and bump
  `config/api.php` `model.version`). The service runs one line per forward pass on purpose: the int8 model
  quantizes activations per tensor, so a padded batch made a line depend on its neighbours.

- After changing catalog embedding logic or synonyms → re-embed with
  `data:embed-catalog --refresh`.
- Queue `retry_after` (`REDIS_QUEUE_RETRY_AFTER`) must exceed the longest job
  timeout, or jobs re-dispatch while still running (duplicate paid LLM calls).
- The catalog embed job self-chains in small batches — safe to interrupt/resume.
- `AnalyzeInvoiceUploadJob`'s timeout (1500 s) is deliberately ABOVE retry_after:
  a per-upload cache lock makes the copy the queue re-releases back off. Don't
  copy that pattern without such a lock.
- The `invoice_lines` view reads e_invoices / classification_items /
  rubricator_nodes / import_batches columns: Postgres refuses to change the type of
  a column a view uses — drop and recreate the view in such a migration. Adding a
  column the chat should see = add it to the view AND to `MetadataCatalogSeeder`.
