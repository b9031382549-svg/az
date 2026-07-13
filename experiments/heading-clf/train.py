#!/usr/bin/env python3
"""Experiment B — linear heading classifier over frozen bge-m3 embeddings.

Trains a multinomial logistic regression on precedent embeddings -> 4-digit HS
heading, and reports top-K accuracy on (a) a held-out 10% of precedents
(in-domain: measures the raw signal in the embeddings) and (b) the Fedor gold
invoice items (real-world). Compare gold top-24 to the retrieval recall@24 (58%).

Read-only w.r.t. the DB. Usage: train.py TRAIN.tsv GOLD.tsv  (lines: "heading\\t[v1,v2,...]")
"""
import sys, time, warnings
import numpy as np
warnings.filterwarnings("ignore")
from sklearn.linear_model import SGDClassifier
from sklearn.preprocessing import normalize
from sklearn.model_selection import train_test_split

TRAIN = sys.argv[1]
GOLD = sys.argv[2] if len(sys.argv) > 2 else None
FIT_N = int(sys.argv[3]) if len(sys.argv) > 3 else 60000  # subsample fit for speed
DIM = 1024


def load(path):
    with open(path) as f:
        n = sum(1 for _ in f)
    X = np.empty((n, DIM), dtype=np.float32)
    heads = []
    i = 0
    with open(path) as f:
        for line in f:
            tab = line.find("\t")
            if tab < 0:
                continue
            lb = line.find("[", tab)
            rb = line.rfind("]")
            if lb < 0 or rb < 0:
                continue
            vec = np.fromstring(line[lb + 1:rb], sep=",", dtype=np.float32)
            if vec.shape[0] != DIM:
                continue
            X[i] = vec
            heads.append(line[:tab])
            i += 1
    return X[:i], np.array(heads)


t = time.time()
Xtr, ytr = load(TRAIN)
print(f"train loaded {Xtr.shape} in {time.time()-t:.0f}s", file=sys.stderr)
Xtr = normalize(Xtr)

Xg = yg = None
if GOLD:
    Xg, yg = load(GOLD)
    Xg = normalize(Xg)
    print(f"gold loaded {Xg.shape}", file=sys.stderr)

# in-domain held-out split
Xa, Xb, ya, yb = train_test_split(Xtr, ytr, test_size=0.1, random_state=0)

# Nearest-centroid classifier: per-heading mean embedding, rank headings by cosine.
# Fast, scales to full data, and directly reflects the embedding geometry.
t = time.time()
labels = np.unique(ya)
cent = np.zeros((len(labels), DIM), dtype=np.float32)
for i, h in enumerate(labels):
    cent[i] = Xa[ya == h].mean(axis=0)
cent = normalize(cent)
print(f"centroids {cent.shape} from {len(Xa)} samples in {time.time()-t:.0f}s", file=sys.stderr)
train_headings = set(ytr.tolist())


def topk(X, y, Ks=(1, 5, 10, 24)):
    S = X @ cent.T  # (n, n_headings) cosine (rows already L2-normalized)
    order = np.argsort(-S, axis=1)[:, : max(Ks)]
    top = labels[order]
    out = {}
    for K in Ks:
        out[K] = float(np.mean([y[i] in top[i, :K] for i in range(len(y))]))
    return out


print("\n=== IN-DOMAIN  (held-out 10% precedents; = raw signal in embeddings) ===")
for K, v in topk(Xb, yb).items():
    print(f"top-{K:<2}: {v*100:5.1f}%")

if Xg is not None and len(yg) > 0:
    seen = np.mean([h in train_headings for h in yg])
    print(f"\n=== GOLD  (Fedor invoice items, n={len(yg)}) ===")
    print(f"(ceiling: {seen*100:.1f}% of gold headings exist in training)")
    for K, v in topk(Xg, yg).items():
        print(f"top-{K:<2}: {v*100:5.1f}%")
    print("\n(compare gold top-24 to retrieval recall@24 = 58.4%)")
else:
    print("\n(gold skipped — embedding still running)")
