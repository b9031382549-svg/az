"""Sorter: what kind of line is this — a good, a service, or trash (names no product)?

A fine-tuned XLM-R sequence classifier (the "sorter", trained on human-labelled invoice lines),
served from ONNX Runtime on CPU. The model files are NOT in git: they are mounted read-only at
SORTER_MODEL_DIR (prod: /opt/az-assets/sorter) and described by its meta.json. The service only
returns probabilities; what to do with them (the trash threshold, the service rule) is decided by
the app (config/classify.php → sorter).

    GET  /health                 → {"ok": true, "model": ..., "sha256": ...}
    POST /classify {"texts": []} → {"labels": ["good","service","trash"], "probs": [[g, s, t], ...]}

Standard library HTTP on purpose: one tiny endpoint, no web framework to pin and patch.
"""
import json
import os
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import numpy as np
import onnxruntime as ort
from tokenizers import Tokenizer

MAX_REQUEST = 1000   # texts per request


class Sorter:
    def __init__(self, model_dir: str, threads: int):
        with open(os.path.join(model_dir, 'meta.json'), encoding='utf-8') as f:
            self.meta = json.load(f)
        self.labels = list(self.meta['labels'])

        opts = ort.SessionOptions()
        # The CPU is shared with Ollama, Postgres and the PHP workers — keep the footprint small.
        opts.intra_op_num_threads = threads
        opts.inter_op_num_threads = 1
        self.session = ort.InferenceSession(os.path.join(model_dir, self.meta['file']), opts,
                                            providers=['CPUExecutionProvider'])

        self.tokenizer = Tokenizer.from_file(os.path.join(model_dir, 'tokenizer.json'))
        # The length the model was trained AND calibrated at: a different cut moves the
        # probabilities the app's trash threshold was derived from.
        self.tokenizer.enable_truncation(max_length=int(self.meta['max_tokens']))
        self.tokenizer.no_padding()
        # One forward pass at a time; the threads above already use the cores we give it.
        self.lock = threading.Lock()

    def predict(self, texts: list[str]) -> list[list[float]]:
        # ONE line per forward pass, never a padded batch: the int8 model quantizes its
        # activations per tensor at run time, so in a batch a line's probabilities would
        # depend on its neighbours (measured: up to 0.55). Alone, a line always gets the same
        # answer — the one the threshold was calibrated on. It is also faster (no padding).
        out: list[list[float]] = []
        for enc in self.tokenizer.encode_batch(texts):
            feeds = {
                'input_ids': np.array([enc.ids], dtype=np.int64),
                'attention_mask': np.array([enc.attention_mask], dtype=np.int64),
            }
            with self.lock:
                logits = self.session.run(['logits'], feeds)[0][0]
            z = np.exp(logits - logits.max())
            out.append((z / z.sum()).tolist())
        return out


class Handler(BaseHTTPRequestHandler):
    sorter: Sorter

    def _send(self, code: int, body: dict) -> None:
        data = json.dumps(body, ensure_ascii=False).encode('utf-8')
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self) -> None:
        if self.path == '/health':
            self._send(200, {'ok': True, 'model': self.sorter.meta.get('name'), 'sha256': self.sorter.meta.get('sha256')})
        else:
            self._send(404, {'error': 'not found'})

    def do_POST(self) -> None:
        if self.path != '/classify':
            self._send(404, {'error': 'not found'})
            return
        try:
            body = json.loads(self.rfile.read(int(self.headers.get('Content-Length') or 0)) or b'{}')
            texts = body.get('texts')
            if not isinstance(texts, list) or not all(isinstance(t, str) for t in texts) or len(texts) > MAX_REQUEST:
                raise ValueError(f'"texts" must be a list of at most {MAX_REQUEST} strings')
        except (ValueError, json.JSONDecodeError) as e:
            self._send(400, {'error': str(e)})
            return
        self._send(200, {'labels': self.sorter.labels, 'probs': self.sorter.predict(texts),
                         'model': self.sorter.meta.get('name')})

    def log_message(self, fmt: str, *args) -> None:  # health checks every few seconds — keep the log quiet
        if not self.path.startswith('/health'):
            sys.stderr.write('%s %s\n' % (self.address_string(), fmt % args))


def main() -> None:
    Handler.sorter = Sorter(os.environ.get('SORTER_MODEL_DIR', '/models/sorter'), int(os.environ.get('SORTER_THREADS', '2')))
    port = int(os.environ.get('SORTER_PORT', '8000'))
    print(f"sorter: {Handler.sorter.meta.get('name')} on :{port}", flush=True)
    ThreadingHTTPServer(('0.0.0.0', port), Handler).serve_forever()


if __name__ == '__main__':
    main()
