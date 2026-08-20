#!/usr/bin/env python3
"""Ollama-compatible embed endpoint with ZERO extra deps beyond sentence-transformers
(uses stdlib http.server, no flask). Lets a container mount host-installed
site-packages (torch + sentence-transformers) and serve with no pip step.

  PYTHONPATH=/host-sp python serve_embedder_stdlib.py --model /model --port 11500
"""
import argparse, json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ap = argparse.ArgumentParser()
ap.add_argument("--model", required=True)
ap.add_argument("--host", default="0.0.0.0")
ap.add_argument("--port", type=int, default=11500)
a = ap.parse_args()

from sentence_transformers import SentenceTransformer

print(f"loading {a.model} ...", flush=True)
model = SentenceTransformer(a.model)
print("model loaded", flush=True)


class H(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def _send(self, code, obj):
        body = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path.startswith("/api/tags"):
            self._send(200, {"models": [{"name": "bge-m3", "model": "bge-m3"}]})
        else:
            self._send(404, {"error": "not found"})

    def do_POST(self):
        if not self.path.startswith("/api/embed"):
            self._send(404, {"error": "not found"})
            return
        n = int(self.headers.get("Content-Length", 0))
        body = json.loads(self.rfile.read(n) or b"{}")
        inp = body.get("input", [])
        if isinstance(inp, str):
            inp = [inp]
        vecs = model.encode(inp, normalize_embeddings=True, convert_to_numpy=True)
        self._send(200, {"embeddings": [v.tolist() for v in vecs]})


print(f"serving on {a.host}:{a.port}", flush=True)
ThreadingHTTPServer((a.host, a.port), H).serve_forever()
