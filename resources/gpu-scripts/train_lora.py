#!/usr/bin/env python3
"""QLoRA fine-tune Llama-3.3-70B on the gold Direct set. RUN ON THE RENTED GPU VM.
Pilot:  ~/venv/bin/python train_lora.py --data train_pilot.jsonl --out /home/ubuntu/adapter --epochs 1
Full:   ~/venv/bin/python train_lora.py --data train.jsonl        --out /home/ubuntu/adapter_full --epochs 2
Produces a LoRA adapter dir -> serve with `vllm --enable-lora` (see LAUNCH_GPU.md).
Deps (on the VM venv): pip install unsloth trl datasets  (unsloth pulls a matching torch/bitsandbytes).

Versioned copy of the field-tested trainer + a machine-readable HEARTBEAT so the app's
gpu:tick poller can show a live progress bar (step/total/loss/eta) WITHOUT any long-running
PHP job. --heartbeat writes a tiny JSON atomically every logging step; that is the only
addition over the original research-data/finetune/gpu-run/train_lora.py.
"""
import argparse, json, os, time, tempfile
ap = argparse.ArgumentParser()
ap.add_argument("--data", required=True)
ap.add_argument("--base", default="/home/ubuntu/llama70")  # local snapshot; or unsloth/Llama-3.3-70B-Instruct
ap.add_argument("--out", default="/home/ubuntu/adapter")
ap.add_argument("--epochs", type=float, default=1.0)
ap.add_argument("--rank", type=int, default=16)        # must match vllm --max-lora-rank
ap.add_argument("--maxseq", type=int, default=1024)
ap.add_argument("--bsz", type=int, default=8)          # lower to 4/2 if OOM
ap.add_argument("--accum", type=int, default=4)
ap.add_argument("--heartbeat", default="")             # path to write {step,total_steps,loss,eta} JSON
a = ap.parse_args()

from unsloth import FastLanguageModel
from unsloth.chat_templates import train_on_responses_only
from datasets import Dataset
from transformers import TrainerCallback
from trl import SFTTrainer, SFTConfig

model, tok = FastLanguageModel.from_pretrained(
    a.base, max_seq_length=a.maxseq, load_in_4bit=True, dtype=None)
model = FastLanguageModel.get_peft_model(
    model, r=a.rank, lora_alpha=a.rank * 2,
    target_modules=["q_proj", "k_proj", "v_proj", "o_proj", "gate_proj", "up_proj", "down_proj"],
    lora_dropout=0.0, bias="none", use_gradient_checkpointing="unsloth", random_state=7)

rows = [json.loads(l) for l in open(a.data)]
ds = Dataset.from_list([{"text": tok.apply_chat_template(r["messages"], tokenize=False)} for r in rows])
print(f"train rows: {len(ds)}")


class Heartbeat(TrainerCallback):
    """Write {status,step,total_steps,loss,eta_seconds,updated_at} atomically each log.
    The poller reads this file over SSH; absence/staleness is treated as 'unknown', a
    training/failed status is inferred from the process + SAVED marker, not from here."""

    def __init__(self, path):
        self.path = path
        self.t0 = time.time()

    def _write(self, obj):
        if not self.path:
            return
        obj["updated_at"] = int(time.time())
        d = os.path.dirname(self.path) or "."
        fd, tmp = tempfile.mkstemp(dir=d)
        with os.fdopen(fd, "w") as f:
            json.dump(obj, f)
        os.replace(tmp, self.path)  # atomic — the poller never reads a half-written file

    def on_train_begin(self, args, state, control, **kw):
        self._write({"status": "training", "step": 0, "total_steps": int(state.max_steps or 0), "loss": None, "eta_seconds": None})

    def on_log(self, args, state, control, logs=None, **kw):
        step, total = int(state.global_step), int(state.max_steps or 0)
        loss = (logs or {}).get("loss")
        eta = None
        if step > 0 and total > 0:
            eta = int((time.time() - self.t0) / step * (total - step))
        self._write({"status": "training", "step": step, "total_steps": total, "loss": loss, "eta_seconds": eta})

    def on_train_end(self, args, state, control, **kw):
        self._write({"status": "saving", "step": int(state.global_step), "total_steps": int(state.max_steps or 0), "loss": None, "eta_seconds": 0})


trainer = SFTTrainer(
    model=model, tokenizer=tok, train_dataset=ds,
    callbacks=[Heartbeat(a.heartbeat)],
    args=SFTConfig(
        dataset_text_field="text", max_seq_length=a.maxseq,
        per_device_train_batch_size=a.bsz, gradient_accumulation_steps=a.accum,
        warmup_ratio=0.03, num_train_epochs=a.epochs, learning_rate=2e-4,
        logging_steps=20, optim="adamw_8bit", weight_decay=0.01,
        lr_scheduler_type="cosine", seed=7, output_dir=a.out + "_ckpt",
        save_strategy="epoch", bf16=True, report_to="none"))
# CRUCIAL: compute loss ONLY on the assistant JSON, not on the prompt (else it memorises prompts)
trainer = train_on_responses_only(
    trainer,
    instruction_part="<|start_header_id|>user<|end_header_id|>\n\n",
    response_part="<|start_header_id|>assistant<|end_header_id|>\n\n")
trainer.train()
model.save_pretrained(a.out); tok.save_pretrained(a.out)
print("SAVED adapter ->", a.out)
