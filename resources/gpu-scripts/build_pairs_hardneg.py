#!/usr/bin/env python3
"""Mine v2 training triplets on the GPU: for each anchor pick the NEAREST non-service
leaf under its gold heading as the positive, and the nearest WRONG-heading leaves as
hard negatives. RUN ON THE RENTED GPU VM.

  python build_pairs_hardneg.py --anchors anchors.jsonl --leaves catalog_leaves.jsonl \
      --out train_triplets.jsonl --neg 4

Uses the STOCK bge-m3 for mining (unbiased; and picking among leaves of the known-correct
heading is easy even for the base model). Output: {anchor, positive, negatives:[...]}.
Deps: sentence-transformers, torch, numpy.
"""
import argparse, json, numpy as np, torch
from sentence_transformers import SentenceTransformer

ap = argparse.ArgumentParser()
ap.add_argument("--anchors", required=True)      # {anchor, heading}
ap.add_argument("--leaves", required=True)        # {code, heading, text, misc}
ap.add_argument("--out", required=True)
ap.add_argument("--base", default="BAAI/bge-m3")
ap.add_argument("--neg", type=int, default=4)
ap.add_argument("--bsz", type=int, default=1024)
a = ap.parse_args()

model = SentenceTransformer(a.base)

leaves = [json.loads(l) for l in open(a.leaves)]
leaf_text = [x["text"] for x in leaves]
leaf_head = np.array([x["heading"] for x in leaves])
leaf_misc = np.array([bool(x["misc"]) for x in leaves])
print(f"embedding {len(leaves)} catalog leaves ...", flush=True)
leaf_emb = model.encode(leaf_text, normalize_embeddings=True, convert_to_numpy=True, batch_size=256, show_progress_bar=True)

anchors = [json.loads(l) for l in open(a.anchors)]
anc_text = [x["anchor"] for x in anchors]
anc_head = np.array([x["heading"] for x in anchors])
print(f"embedding {len(anchors)} anchors ...", flush=True)
anc_emb = model.encode(anc_text, normalize_embeddings=True, convert_to_numpy=True, batch_size=256, show_progress_bar=True)

leaf_t = torch.tensor(leaf_emb, device="cuda")  # (L, d)
out = open(a.out, "w")
n_pos_fallback = 0

for i in range(0, len(anchors), a.bsz):
    a_t = torch.tensor(anc_emb[i:i + a.bsz], device="cuda")
    sims = (a_t @ leaf_t.T).cpu().numpy()  # (b, L)
    for j in range(sims.shape[0]):
        gi = i + j
        h = anc_head[gi]
        s = sims[j]
        same = leaf_head == h
        pos_mask = same & (~leaf_misc)
        if not pos_mask.any():
            pos_mask = same
            n_pos_fallback += 1
        pidx = np.where(pos_mask)[0]
        pos = int(pidx[np.argmax(s[pidx])])          # nearest allowed leaf under gold heading
        neg_s = s.copy()
        neg_s[same] = -1e9                            # exclude gold heading from negatives
        k = min(a.neg, int((~same).sum()))
        nidx = np.argpartition(-neg_s, k)[:k]
        nidx = nidx[np.argsort(-neg_s[nidx])]
        out.write(json.dumps({
            "anchor": anc_text[gi],
            "positive": leaf_text[pos],
            "negatives": [leaf_text[int(x)] for x in nidx],
        }, ensure_ascii=False) + "\n")
    print(f"  {min(i + a.bsz, len(anchors))}/{len(anchors)}", flush=True)

out.close()
print(f"WROTE {a.out} | positives via fallback (all-misc heading): {n_pos_fallback}")
