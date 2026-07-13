# Experiment B — linear/centroid heading classifier over frozen bge-m3 embeddings

**Goal:** cheaply find where the vector's ceiling is — in the embeddings themselves,
or in how we use them. Read-only (no DB writes, no prod). Test set = 1010 Fedor gold
goods (4-digit heading). Query = raw invoice name (digit-tokens dropped), embedded with
our bge-m3. Classifier = nearest-centroid (per-heading mean embedding, ranked by cosine).

## Results (top-K = correct heading among top-K)

| Train → Test                     | top-1 | top-5 | top-24 |
|----------------------------------|------:|------:|-------:|
| **In-domain** (held-out precedents) | 40.6% | 66.4% | **83.7%** |
| Precedents → gold                | 19.6% | 33.9% | 49.7% |
| Catalog (AZ nomenclature) → gold | 11.3% | 25.6% | 41.7% |
| Precedents+Catalog → gold        | 18.2% | 31.8% | 49.0% |
| *current retrieval pipeline*     |   —   |   —   | *58.4%* |

## Conclusions

1. **The embeddings carry strong heading signal** — in-domain top-24 = 83.7% (top-1 40.6%)
   with a trivial centroid classifier. The "map" separates headings well WHEN the query
   matches the reference distribution/style. → A full GPU fine-tune of the embedding model
   is NOT the first priority; the geometry is already decent.

2. **A classifier over the current embeddings does NOT beat retrieval on real invoices**
   (best gold top-24 = 49.7% < 58.4%). So the ceiling is not "we read the embeddings badly."

3. **The bottleneck is the QUERY, not the model or the corpus.** The 34pp drop from
   in-domain (83.7%) to gold (49.7%) is the noisy, brand/size-laden, distribution-shifted
   invoice text. Catalog-trained is even worse (41.7%) because formal nomenclature names are
   stylistically far from invoice items; precedents (real product names) transfer a bit better.

## Implication

- Highest-leverage lever = **clean the query** (the LLM "brief": "Beluga Nobl 0.05 L" → "araq").
  It converts noisy invoice text into the clean short-name style the embeddings handle at ~84%.
  Everything so far was measured on RAW names — the real prod recall (which retrieves on the
  brief) is unknown and likely much higher than 58%. **Measure retrieval recall with the brief
  query** — this recalibrates whether the result is actually "weak."
- Second lever = **AZ invoice-distribution reference data** (verified human corrections from the
  review queue), which matches the query distribution far better than European precedents.
- Fine-tune embeddings on GPU only if the above plateau.

Scripts: `embed_gold.php` (query embeddings), `train.py` (centroid classifier). Data in scratchpad.
