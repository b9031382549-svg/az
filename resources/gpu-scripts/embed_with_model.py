#!/usr/bin/env python3
"""Embed text with a (fine-tuned) SentenceTransformer. RUN ON THE RENTED GPU VM.

One tool for BOTH sides of the post-FT eval, so query and index share exactly one
model — the whole point (a fine-tuned model makes a NEW vector space; stock and FT
vectors are not comparable).

  # catalog index passages (from `catalog:dump-passages`, {code,text} per line):
  python embed_with_model.py --model /home/ubuntu/bge_ft --in catalog_passages.jsonl \
      --text-field text --key-field code --out catalog_ft_vectors.jsonl
  #   -> {"code","vector"} per line ; \copy into a catalog_ft(code,embedding) table.

  # test queries (test.jsonl, {name,gold}); emit vectors ALIGNED to file order:
  python embed_with_model.py --model /home/ubuntu/bge_ft --in test.jsonl \
      --text-field name --out test_query_vectors.jsonl
  #   -> one bare [float,...] per line ; feed to `classify:vector-baseline --query-vectors`.

Deps (VM venv): pip install "sentence-transformers>=3.3"
Embeddings are L2-normalized (cosine == the pgvector <=> operator the eval uses).
"""
import argparse, json

ap = argparse.ArgumentParser()
ap.add_argument("--model", required=True)
ap.add_argument("--in", dest="inp", required=True)
ap.add_argument("--out", required=True)
ap.add_argument("--text-field", default="text")
ap.add_argument("--key-field", default="")   # if set, emit {key-field, vector}; else a bare vector per line
ap.add_argument("--bsz", type=int, default=128)
a = ap.parse_args()

from sentence_transformers import SentenceTransformer

rows = [json.loads(l) for l in open(a.inp) if l.strip()]
texts = [str(r.get(a.text_field, "")) for r in rows]
print(f"encoding {len(texts)} texts with {a.model}")

model = SentenceTransformer(a.model)
vecs = model.encode(texts, batch_size=a.bsz, normalize_embeddings=True,
                    convert_to_numpy=True, show_progress_bar=True)

with open(a.out, "w") as f:
    for r, v in zip(rows, vecs):
        v = [round(float(x), 7) for x in v]
        if a.key_field:
            f.write(json.dumps({a.key_field: r[a.key_field], "vector": v}) + "\n")
        else:
            f.write(json.dumps(v) + "\n")
print("WROTE ->", a.out)
