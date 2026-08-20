#!/usr/bin/env python3
"""Serve a (fine-tuned) SentenceTransformer as an Ollama-compatible embed endpoint.

Lets the app use the FT embedder with ZERO code change — just point OLLAMA_URL at
this server for a test run. Mimics Ollama's POST /api/embed {model, input:[...]}
-> {embeddings:[[...]]}. Runs fine on CPU: the full mechanism is LLM-bound, so
query-embedding latency is negligible; keep the GPU only for the one-off re-bake +
catalog_ft/precedents_ft embedding, then serve this locally and tear the GPU down.

  pip install flask sentence-transformers
  python serve_embedder.py --model research-data/finetune/bge_ft --port 11500
  # then for the test run: OLLAMA_URL=http://host:11500 + retrieval tables = *_ft
"""
import argparse
from flask import Flask, request, jsonify
from sentence_transformers import SentenceTransformer

ap = argparse.ArgumentParser()
ap.add_argument("--model", required=True)
ap.add_argument("--host", default="0.0.0.0")
ap.add_argument("--port", type=int, default=11500)
a = ap.parse_args()

model = SentenceTransformer(a.model)
app = Flask(__name__)


@app.post("/api/embed")
def embed():
    body = request.get_json(force=True)
    inp = body.get("input", [])
    if isinstance(inp, str):
        inp = [inp]
    # normalize_embeddings=True → cosine, matching how catalog_ft was embedded.
    vecs = model.encode(inp, normalize_embeddings=True, convert_to_numpy=True)
    return jsonify({"embeddings": [v.tolist() for v in vecs]})


@app.get("/api/tags")
def tags():  # so a health check / model list doesn't 404
    return jsonify({"models": [{"name": "bge-m3", "model": "bge-m3"}]})


if __name__ == "__main__":
    print(f"serving {a.model} on {a.host}:{a.port} (Ollama-compatible /api/embed)")
    app.run(host=a.host, port=a.port, threaded=True)
