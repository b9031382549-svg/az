#!/usr/bin/env python3
"""v2 contrastive fine-tune of bge-m3 WITH hard negatives + per-epoch checkpoints.
RUN ON THE RENTED GPU VM.

Data = triplets {anchor, positive, negatives:[...]} from build_pairs_hardneg.py.
Loss = MultipleNegativesRankingLoss with explicit hard negatives (columns
anchor, positive, negative_1..negative_N) PLUS the usual in-batch negatives.

Saves a full model at the END OF EACH EPOCH → <out>_e1, <out>_e2, ... so the pure-
vector eval can compare epoch 1 vs 2 and pick the best (no guessing on epoch count).

  python train_embedder_v2.py --data train_triplets.jsonl --out /home/ubuntu/bge_ft_v2 --epochs 2 --negs 4
Deps: sentence-transformers>=3.3, datasets
"""
import argparse, json, os, time, tempfile
ap = argparse.ArgumentParser()
ap.add_argument("--data", required=True)
ap.add_argument("--base", default="BAAI/bge-m3")
ap.add_argument("--out", default="/home/ubuntu/bge_ft_v2")
ap.add_argument("--epochs", type=int, default=2)
ap.add_argument("--negs", type=int, default=4)          # hard negatives per row to use
ap.add_argument("--bsz", type=int, default=64)
ap.add_argument("--maxseq", type=int, default=256)
ap.add_argument("--lr", type=float, default=2e-5)
ap.add_argument("--heartbeat", default="")
a = ap.parse_args()

from sentence_transformers import (
    SentenceTransformer, SentenceTransformerTrainer, SentenceTransformerTrainingArguments)
from sentence_transformers.losses import MultipleNegativesRankingLoss
from datasets import Dataset
from transformers import TrainerCallback

model = SentenceTransformer(a.base)
model.max_seq_length = a.maxseq

rows = [json.loads(l) for l in open(a.data)]
cols = {"anchor": [], "positive": []}
for k in range(a.negs):
    cols[f"negative_{k+1}"] = []
for r in rows:
    cols["anchor"].append(r["anchor"])
    cols["positive"].append(r["positive"])
    negs = r.get("negatives", [])
    for k in range(a.negs):
        # pad short negative lists by reusing the last one (rare); keeps columns aligned
        cols[f"negative_{k+1}"].append(negs[k] if k < len(negs) else (negs[-1] if negs else r["positive"]))
ds = Dataset.from_dict(cols)
print(f"train rows: {len(ds)} | columns: {list(cols)}")

loss = MultipleNegativesRankingLoss(model)


class Heartbeat(TrainerCallback):
    def __init__(self, path):
        self.path, self.t0 = path, time.time()

    def _write(self, obj):
        if not self.path:
            return
        obj["updated_at"] = int(time.time())
        d = os.path.dirname(self.path) or "."
        fd, tmp = tempfile.mkstemp(dir=d)
        with os.fdopen(fd, "w") as f:
            json.dump(obj, f)
        os.replace(tmp, self.path)

    def on_log(self, args, state, control, logs=None, **kw):
        step, total = int(state.global_step), int(state.max_steps or 0)
        eta = int((time.time() - self.t0) / step * (total - step)) if step and total else None
        self._write({"status": "training", "step": step, "total_steps": total,
                     "loss": (logs or {}).get("loss"), "eta_seconds": eta})


class EpochSaver(TrainerCallback):
    """Save a full, reusable SentenceTransformer at the end of each epoch."""
    def __init__(self, model, out):
        self.model, self.out = model, out

    def on_epoch_end(self, args, state, control, **kw):
        e = int(round(state.epoch or 0))
        path = f"{self.out}_e{e}"
        self.model.save(path)
        print(f"SAVED epoch {e} -> {path}", flush=True)


trainer = SentenceTransformerTrainer(
    model=model, train_dataset=ds, loss=loss,
    callbacks=[Heartbeat(a.heartbeat), EpochSaver(model, a.out)],
    args=SentenceTransformerTrainingArguments(
        output_dir=a.out + "_ckpt", num_train_epochs=a.epochs,
        per_device_train_batch_size=a.bsz, learning_rate=a.lr,
        warmup_ratio=0.05, lr_scheduler_type="cosine", bf16=True,
        logging_steps=20, save_strategy="no", seed=7, report_to="none"))
trainer.train()
print("DONE. Saved per-epoch checkpoints:", [f"{a.out}_e{e}" for e in range(1, a.epochs + 1)])
