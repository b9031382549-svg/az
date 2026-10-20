# Deploying eInvoice AI on premise

The service classifies invoice lines into XİF MN codes (API: [API.md](API.md)) and answers
questions about invoices in a chat. This guide covers an install where the LLM runs on
your own GPU, the embeddings on your own server, and only the web search goes out, to
OpenRouter. Every setting is an environment variable: [.env.onprem.example](.env.onprem.example)
lists all of them with the values our production runs with.

## Components

| Component | Image / command | Notes |
|---|---|---|
| app | `docker/app/Dockerfile`, target `prod`; default command | nginx + php-fpm on port 80. Liveness: `GET /up` |
| worker | same image; `php artisan horizon`, user `www-data`, `HOME=/var/www` | background jobs, up to 16 processes × 256 MB: give it ~4.5 GB |
| scheduler | same image; `php artisan schedule:work`, user `www-data` | exactly one replica |
| sorter | `docker/sorter/Dockerfile` | port 8000, `GET /health`; reads the sorter-v2 files from `SORTER_MODEL_DIR` (default `/models/sorter`); `SORTER_THREADS=2`; ~1.5 GB |
| PostgreSQL 16 | e.g. `pgvector/pgvector:pg16` | needs the `vector` (>= 0.5, HNSW) and `pg_trgm` extensions |
| Redis 7 | `redis-server --appendonly yes --appendfsync everysec --maxmemory 512mb --maxmemory-policy noeviction` | the job queue; must not evict |
| LLM | vLLM on the GPU (below) | Llama-3.3-70B-Instruct + our LoRA `xif` |
| Embeddings | any OpenAI-compatible `/v1/embeddings` server, or Ollama (below) | model `bge-ft-v3`, 1024 dims |

- **app and worker share one volume** at `/var/www/html/storage/app/uploads` (big uploads
  are saved by the app and read by the worker). On Kubernetes: ReadWriteMany, owned by
  `www-data`.
- Logs go to stdout/stderr. Sessions and cache live in PostgreSQL, the queue in Redis.
- `docker-compose.prod.yml` is our own production and a working reference. Its worker
  mounts under `/opt/az-secrets` and `/opt/az-assets` are for renting GPUs from Nebius:
  not needed here (`GPU_PROVISIONING=false`).

## Files we hand over

They are in this project's Package Registry (Deploy → Package Registry), each with its
sha256:

| Package / version | Files | Goes to |
|---|---|---|
| `xif-lora` / `test-45k` | `xif-lora-test-45k.tar` | unpack on the GPU server; vLLM `--lora-modules xif=<dir>` |
| `bge-ft-v3` / `v3` | `bge-ft-v3.gguf`, `Modelfile` | the embedding server |
| `sorter` / `v2` | `model.onnx`, `tokenizer.json`, `meta.json` | the sorter's `SORTER_MODEL_DIR` |
| `classifier-data` / date | `classifier-data.sql.gz`, `README.txt` | the database, once (below) |

Download with a token that can read the project:

```bash
curl --header "PRIVATE-TOKEN: $TOKEN" -O \
  "https://gitlab.e-taxes.gov.az/api/v4/projects/2145/packages/generic/<package>/<version>/<file>"
```

The Llama weights come from Hugging Face: `meta-llama/Llama-3.3-70B-Instruct` (Meta licence).

## LLM server (vLLM)

Our exact command, on 1× H100 80 GB:

```bash
vllm serve <Llama-3.3-70B-Instruct dir> \
  --served-model-name base meta-llama/Llama-3.3-70B-Instruct \
  --quantization fp8 --max-model-len 8192 --gpu-memory-utilization 0.95 --enforce-eager \
  --enable-lora --lora-modules xif=<adapter dir> --max-lora-rank 16 \
  --host 0.0.0.0 --port 8000 [--api-key <LLM_API_KEY>]
```

- FP8 is what all our quality numbers were measured with. bf16 works too (2× 80 GB,
  `--tensor-parallel-size 2`); we have not measured it.
- 8192 tokens of context is enough: our longest request to this model is ~3.9k tokens.
- The adapter is PEFT LoRA, r=16, alpha=32, on all attention and MLP projections.
- Settings: `LLM_BASE_URL=http://<host>:8000/v1`, `LLM_API_KEY`, `LLM_BASE_MODEL=base`,
  `LLM_TUNED_MODEL=xif`. Every stage set to `gpu:base` / `gpu:tuned` goes to this server.
- The GPU is required. If it cannot answer, classification jobs wait and retry for up to
  `CLASSIFY_WAIT_FOR_MODELS_HOURS` (24 h) instead of going on without it.

## Embedding server

`bge-ft-v3` is our fine-tune of `BAAI/bge-m3` (1024 dims, CLS pooling, cosine). The vectors
in the data dump were made with it, so the server must serve exactly this model.

- **llama.cpp**, OpenAI-compatible, straight from the GGUF:
  `llama-server -m bge-ft-v3.gguf --embeddings --pooling cls --host 0.0.0.0 --port 8080`
  → `EMBED_DRIVER=openai`, `EMBED_BASE_URL=http://<host>:8080/v1`, `EMBED_MODEL` = the name
  it serves the model under.
- **vLLM / TEI** need the model in Hugging Face format; we add it to the `bge-ft-v3` package.
- **Ollama:** `ollama create bge-ft-v3 -f Modelfile` next to the GGUF →
  `EMBED_DRIVER=ollama`, `OLLAMA_URL`, `OLLAMA_EMBED_MODEL=bge-ft-v3`.

If you ever change the embedding server, re-make the stored vectors with it once:
`php artisan data:embed-catalog --refresh` and `php artisan data:embed-precedents --refresh`.

## Database rights

The first `migrate` runs `CREATE EXTENSION vector` and `CREATE EXTENSION pg_trgm`, and
creates the read-only role the chat's SQL runs as (`DB_RO_USERNAME` / `DB_RO_PASSWORD`). The
app's DB user needs those rights, or a DBA creates both extensions and the role first.

## First install

Run these once, as a one-off job with the app image, the full environment and user
`www-data`:

```bash
php artisan migrate --force
php artisan db:seed --force                     # user admin / admin + the chat's column descriptions
echo "$ADMIN_PASSWORD" | php artisan user:password admin --stdin   # replace admin / admin (>= 12 chars)
gunzip -c classifier-data.sql.gz | psql -v ON_ERROR_STOP=1 -d <database>   # see its README.txt
# then the release steps below
```

Then start app, worker and scheduler.

## Every release

One-off job before the new pods start (all steps are idempotent):

```bash
php artisan migrate --force
php artisan nlsql:grant                                                      # the chat role's grants
php artisan db:seed --class='Database\Seeders\MetadataCatalogSeeder' --force  # the chat's column descriptions
php artisan data:build-rubricator
php artisan db:seed --class='Database\Seeders\HsCardsSeeder' --force
php artisan catalog:sync-card-synonyms
php artisan catalog:build-search-text
```

Then restart app, worker and scheduler. Migrations run only here, never in an entrypoint.

Optional: `php artisan optimize` caches config and routes. It runs inside each app
container (the cache lives there), as `www-data`, never as root, and again after any env
change. Without it the app reads the environment directly.

## Network

- **Outbound at runtime:** only `https://openrouter.ai` (443), for the web search. It
  receives the item name and public catalog texts, never invoice data, VÖEN or amounts.
  Everything else is internal: vLLM, the embedding server, PostgreSQL, Redis, sorter.
- **Building the images** needs Docker Hub (`php:8.3-fpm-bookworm`, `composer:2`,
  `node:22-alpine`, `python:3.12-slim`), Debian apt, Packagist + GitHub (composer), npm and
  PyPI, or your mirrors of them.
- **Browsers:** the UI currently loads Tailwind and fonts from public CDNs.

## Checks

- `GET /up`: the app answers (no auth).
- `GET /api/health-check` with an API token: DB, Redis, queue workers, sorter, the
  embedding model, one vector search, and that the LLM and embedding servers still offer
  their models. Every probe is instant and free; it never classifies. 200 = all up;
  500 names each part that is down.
- `GET /api/version`: our release and the models the chain is configured with.

API tokens are issued in the web UI: Settings → API tokens.
