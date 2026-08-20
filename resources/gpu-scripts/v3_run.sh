#!/bin/bash
# Full v3 pipeline on the rented VM: mine triplets → train 3 epochs (e1/e2/e3) →
# embed catalog+queries for all 3 epochs and precedents for e2/e3. Idempotent-ish;
# writes a marker file per stage so a re-run skips finished stages.
set -e
cd ~
export PYTORCH_CUDA_ALLOC_CONF=expandable_segments:True
PY=~/venv/bin/python

echo "[$(date +%T)] mine triplets"
[ -f train_triplets.jsonl ] || $PY build_pairs_hardneg.py --anchors anchors.jsonl --leaves catalog_leaves.jsonl --out train_triplets.jsonl --neg 4

echo "[$(date +%T)] train 3 epochs"
[ -d bge_ft_v3_e3 ] || $PY train_embedder_v2.py --data train_triplets.jsonl --out /home/ubuntu/bge_ft_v3 \
  --epochs 3 --negs 4 --bsz 32 --maxseq 256 --heartbeat /home/ubuntu/hb.json

for e in e1 e2 e3; do
  echo "[$(date +%T)] embed catalog+queries $e"
  [ -f catalog_v3_$e.jsonl ] || $PY embed_with_model.py --model bge_ft_v3_$e --in catalog_leaves.jsonl --text-field text --key-field code --out catalog_v3_$e.jsonl
  [ -f testq_v3_$e.jsonl ]   || $PY embed_with_model.py --model bge_ft_v3_$e --in test.jsonl --text-field name --out testq_v3_$e.jsonl
done
for e in e2 e3; do
  echo "[$(date +%T)] embed precedents $e"
  [ -f precedents_v3_$e.jsonl ] || $PY embed_with_model.py --model bge_ft_v3_$e --in precedents_texts.jsonl --text-field text --key-field hs6 --out precedents_v3_$e.jsonl
done
echo "ALL_DONE $(date +%T)"
