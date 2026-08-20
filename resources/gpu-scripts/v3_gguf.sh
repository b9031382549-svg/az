#!/bin/bash
# Regenerate v3-e3 and convert it to GGUF on the VM (so we pull the 1.15GB GGUF,
# not the 2.27GB safetensors). Deterministic (seed 7) → reproduces v3-e3.
set -e
cd ~
export PYTORCH_CUDA_ALLOC_CONF=expandable_segments:True
PY=~/venv/bin/python
echo "[$(date +%T)] mine"; [ -f train_triplets.jsonl ] || $PY build_pairs_hardneg.py --anchors anchors.jsonl --leaves catalog_leaves.jsonl --out train_triplets.jsonl --neg 4
echo "[$(date +%T)] train 3ep"; [ -d bge_ft_v3_e3 ] || $PY train_embedder_v2.py --data train_triplets.jsonl --out /home/ubuntu/bge_ft_v3 --epochs 3 --negs 4 --bsz 32 --maxseq 256 --heartbeat /home/ubuntu/hb.json
echo "[$(date +%T)] gguf setup"
[ -d llama.cpp ] || git clone --depth 1 https://github.com/ggerganov/llama.cpp
$PY -m pip install -q gguf sentencepiece protobuf
[ -f bge_ft_v3_e3/sentencepiece.bpe.model ] || curl -sL -o bge_ft_v3_e3/sentencepiece.bpe.model https://huggingface.co/BAAI/bge-m3/resolve/main/sentencepiece.bpe.model
echo "[$(date +%T)] convert to gguf"
$PY llama.cpp/convert_hf_to_gguf.py bge_ft_v3_e3 --outfile bge_ft_v3_e3.gguf --outtype f16
ls -la bge_ft_v3_e3.gguf
echo "GGUF_DONE $(date +%T)"
