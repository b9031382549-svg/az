#!/usr/bin/env python3
"""Contrastive fine-tune of bge-m3 (the RETRIEVAL embedder). RUN ON THE RENTED GPU VM.

This is the embedder track — distinct from train_lora.py (which QLoRA-tunes the
generative Llama-70B). Here we reshape the vector space so noisy invoice queries
land nearer their gold HS heading's catalog passage.

Data: contrastive pairs from `finetune:build-contrastive` — one {"anchor","positive"}
per line. Loss: MultipleNegativesRankingLoss (in-batch negatives — every other
positive in the batch is a negative), so NO explicit hard-negative mining is
needed; a big batch = many/harder negatives, hence --bsz should be as large as
VRAM allows. bge-m3 is only ~560M params, so a single 24-48GB GPU is ample.

  ~/venv/bin/python train_embedder.py --data train_pairs.jsonl --out /home/ubuntu/bge_ft --epochs 1

Deps (VM venv): pip install "sentence-transformers>=3.3" datasets peft accelerate

--lora keeps the base frozen and trains low-rank deltas (preserves bge-m3's general
multilinguality — the reason it is valuable — and resists catastrophic forgetting).
On a 560M model LoRA is about forgetting, not VRAM.
"""
import argparse, json, os, time, tempfile

ap = argparse.ArgumentParser()
ap.add_argument("--data", required=True)                 # {anchor, positive} JSONL
ap.add_argument("--base", default="BAAI/bge-m3")
ap.add_argument("--out", default="/home/ubuntu/bge_ft")
ap.add_argument("--epochs", type=float, default=1.0)
ap.add_argument("--bsz", type=int, default=64)           # in-batch negatives: bigger = harder. Lower if OOM.
ap.add_argument("--maxseq", type=int, default=256)       # invoice lines + catalog leaf are short
ap.add_argument("--lr", type=float, default=2e-5)
ap.add_argument("--lora", action="store_true")
ap.add_argument("--rank", type=int, default=16)
ap.add_argument("--heartbeat", default="")               # JSON {step,total_steps,loss,eta} for the gpu:tick poller
a = ap.parse_args()

from sentence_transformers import (
    SentenceTransformer, SentenceTransformerTrainer, SentenceTransformerTrainingArguments)
from sentence_transformers.losses import MultipleNegativesRankingLoss
from datasets import Dataset
from transformers import TrainerCallback

model = SentenceTransformer(a.base)
model.max_seq_length = a.maxseq

if a.lora:
    from peft import LoraConfig, get_peft_model
    tf = model[0].auto_model  # the underlying XLM-RoBERTa encoder
    tf = get_peft_model(tf, LoraConfig(
        r=a.rank, lora_alpha=a.rank * 2, lora_dropout=0.0, bias="none",
        target_modules=["query", "key", "value", "dense"]))
    model[0].auto_model = tf
    tf.print_trainable_parameters()

rows = [json.loads(l) for l in open(a.data)]
ds = Dataset.from_list([{"anchor": r["anchor"], "positive": r["positive"]} for r in rows])
print(f"train pairs: {len(ds)}")

loss = MultipleNegativesRankingLoss(model)


class Heartbeat(TrainerCallback):
    """Atomic {status,step,total_steps,loss,eta_seconds,updated_at} each log — same
    contract as train_lora.py so the app's poller renders one progress bar for both."""

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

    def on_train_begin(self, args, state, control, **kw):
        self._write({"status": "training", "step": 0, "total_steps": int(state.max_steps or 0), "loss": None, "eta_seconds": None})

    def on_log(self, args, state, control, logs=None, **kw):
        step, total = int(state.global_step), int(state.max_steps or 0)
        eta = int((time.time() - self.t0) / step * (total - step)) if step and total else None
        self._write({"status": "training", "step": step, "total_steps": total, "loss": (logs or {}).get("loss"), "eta_seconds": eta})

    def on_train_end(self, args, state, control, **kw):
        self._write({"status": "saving", "step": int(state.global_step), "total_steps": int(state.max_steps or 0), "loss": None, "eta_seconds": 0})


trainer = SentenceTransformerTrainer(
    model=model, train_dataset=ds, loss=loss, callbacks=[Heartbeat(a.heartbeat)],
    args=SentenceTransformerTrainingArguments(
        output_dir=a.out + "_ckpt", num_train_epochs=a.epochs,
        per_device_train_batch_size=a.bsz, learning_rate=a.lr,
        warmup_ratio=0.05, lr_scheduler_type="cosine", bf16=True,
        logging_steps=20, save_strategy="no", seed=7, report_to="none"))
trainer.train()

# Merge LoRA deltas so the saved model is a plain SentenceTransformer (embed_with_model.py
# and any TEI/Ollama-GGUF path then load it with no PEFT dependency).
if a.lora:
    model[0].auto_model = model[0].auto_model.merge_and_unload()
model.save(a.out)
print("SAVED embedder ->", a.out)
