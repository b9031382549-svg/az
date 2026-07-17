# Model benchmarks — classifier decision stages

Running log of model-comparison runs for the Task-2 classifier (rerank / broker /
direct). Kept as the **baseline to compare against when we fine-tune** (esp. the
Direct path). Append a new dated section per campaign — never rewrite old numbers.

## Method & reference (read this before trusting a number)

- Harness: `php artisan classify:accuracy-test` with `--decider=<model>` (overrides the
  4 decision stages onto one model; retrieval + `expand` held on deepseek so the 24
  candidates every model sees are identical → fair model-to-model comparison).
- **Reference (gold):** Fedor set — `gold_labels` where `source='fedor'`, first 100 of
  `fedor_test_100`. Each item has a known **4-digit HS heading + service flag**.
- **Correct = tool's heading == gold heading** (`*_ok` booleans). Accuracy = ✓ / total.
- **Caveats:** (1) Fedor gold is **AI-labelled (Claude/GPT), not human-verified** → this
  is "agreement with Fedor", not absolute truth; great for *relative* model comparison.
  (2) Measured at **4-digit heading**, not the full 10-digit code. (3) `ensemble` here
  excludes the search stage (conflict = no-answer), so it understates prod.
- Raw per-item results: `research-data/benchmarks/raw/*.jsonl` (gitignored, local).

---

## 2026-07-17 — Nebius model campaign (choose the decision-stage model)

100 Fedor items, retrieval/expand held on deepseek-chat.

### Full pipeline: gpt-oss-120b (Nebius) vs deepseek-chat (prod baseline)

| Stage | deepseek-chat (base) | gpt-oss-120b | Δpp | paired (cand✓base✗ : base✓cand✗) |
|---|---|---|---|---|
| Vector (rerank) | 58% | **74%** | **+16** | 17 : 1 |
| Broker | 64% | 66% | +2 | 10 : 8 |
| **Direct** | **61%** | **60%** | −1 | 7 : 8 (same model both sides → Nebius↔OpenRouter parity) |
| Ensemble | 43% | 57% | +14 | 16 : 2 |

Files: `raw/base_100.jsonl`, `raw/nebius_100.jsonl`.

### Reranker shootout (methods=vector only, 100 items)

| Model | Rerank acc | No-answer | Paired vs gpt-oss | Local fit |
|---|---|---|---|---|
| gpt-oss-120b | **74%** | 2 | — | 80GB-class MoE |
| **Llama-3.3-70B** | **72%** | 0 | 4:6 (tie) | 1×40–48GB dense ✅ |
| Qwen3-32B | 65% | 12 (breaks JSON) | 1:10 | 1×24GB ✅✅ |
| deepseek-chat | 58% | 0 | 1:17 | — |

Files: `raw/llama70_rerank.jsonl`, `raw/qwen32_rerank.jsonl`.

### Speed / tokens (per rerank call, from `llm_usage`)

| Model | avg s | med s | out-tokens | note |
|---|---|---|---|---|
| qwen-2.5-7b (old tier-1) | 2.5 | 2.4 | 79 | fastest |
| deepseek-chat | 4.5 | 4.3 | 67 | |
| Llama-3.3-70B | 5.2 | 4.1 | 80 | steady, non-thinking |
| gpt-oss-120b | 5.2 | 3.0 | **399** | thinks → token/latency tail |
| Qwen3-32B | 6.3 | 4.4 | 83 | slowest + unreliable |

### Decision

Chose **Llama-3.3-70B** for all decision stages (rerank tier1+2, broker, direct, +expand):
quality ties gpt-oss (72 vs 74, within noise) but far more local-friendly (dense 70B,
non-thinking, 5× fewer tokens, reliable). Wired via the `nebius:` prefix (reversible).
Search stays on OpenRouter (`deepseek-v4-flash:online`, needs web).

### Llama-3.3-70B full pipeline (vector+broker+direct) — DONE (`raw/llama_full_100.jsonl`)

| Stage | Llama-3.3-70B | gpt-oss-120b | deepseek (base) |
|---|---|---|---|
| Vector (rerank) | **75%** | 74% | 58% |
| Broker | **47%** ⚠️ | 66% | 64% |
| **Direct** ← fine-tune baseline | **54%** | 60% | 61% |
| Ensemble | **48%** | 57% | 43% |

**KEY FINDING — the reranker shootout (rerank-only) hid this:** Llama ties gpt-oss on
rerank (75 vs 74) but is **−17pp on broker** (47 vs 66) and −6pp on direct → ensemble
48% vs gpt-oss 57%. Broker drop is mostly **abstains, not wrong calls**: broker
correct/wrong/no-answer = Llama 47/35/**18**, deepseek 64/35/1, gpt-oss 66/30/4. Same
wrong count as deepseek (35) but **18 abstains** — Llama (non-thinking 70B) breaks the
broker's multi-step JSON descent format far more often. Likely improvable (response_format
/ retries / prompt), but AS-IS, Llama-everything gives a worse ensemble than gpt-oss.

**Implication:** best-quality-on-Nebius = **Llama rerank/direct + gpt-oss broker** (both
Nebius, one provider) OR gpt-oss everything. Llama-everything = local-simple but −9pp
ensemble. **Direct fine-tune baseline to beat: 54% (Llama) / 60% (gpt-oss) depending on
which model direct runs.**
