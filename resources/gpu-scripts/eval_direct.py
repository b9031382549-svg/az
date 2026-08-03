#!/usr/bin/env python3
"""Direct-stage heading accuracy of a served model on the CLEAN benchmark (748 items).
Hits the VM's OpenAI-compatible vLLM endpoint (no WAF → plain urllib is fine).
Run TWICE for the before/after Direct number:
  python eval_direct.py --model base --base http://<ip>:8000/v1   # baseline (same infra)
  python eval_direct.py --model xif  --base http://<ip>:8000/v1   # fine-tuned
Compares pred 4-digit heading (or SERVICE) to gold. Writes per-item json for inspection.
"""
import argparse, json, re, urllib.request, concurrent.futures
ap = argparse.ArgumentParser()
ap.add_argument("--model", required=True)                 # base | xif
ap.add_argument("--base", required=True)                  # http://<ip>:8000/v1
ap.add_argument("--key", default="sk-vmtest")
ap.add_argument("--gold", default="eval_gold.jsonl")
ap.add_argument("--prompt", default="prompt.txt")
ap.add_argument("--workers", type=int, default=16)
a = ap.parse_args()
PROMPT = open(a.prompt).read()
gold = [json.loads(l) for l in open(a.gold)]

def call(it):
    body = json.dumps({"model": a.model, "temperature": 0, "max_tokens": 200,
        "messages": [{"role": "system", "content": PROMPT},
                     {"role": "user", "content": "ITEM: " + it["name"]}]}).encode()
    req = urllib.request.Request(a.base.rstrip("/") + "/chat/completions", data=body,
        headers={"Authorization": "Bearer " + a.key, "Content-Type": "application/json"})
    pred = None
    try:
        d = json.loads(urllib.request.urlopen(req, timeout=90).read())
        c = d["choices"][0]["message"]["content"] or ""
        m = re.search(r"\{.*\}", c, re.S); obj = json.loads(m.group(0)) if m else {}
        if obj.get("kind") == "service": pred = "SERVICE"
        else:
            h = re.sub(r"\D", "", str(obj.get("heading") or ""))[:4]; pred = h or None
    except Exception:
        pred = None
    return {"name": it["name"][:40], "gold": it["gold"], "pred": pred,
            "src": it.get("src", ""), "ok": pred == it["gold"]}

with concurrent.futures.ThreadPoolExecutor(max_workers=a.workers) as ex:
    res = list(ex.map(call, gold))
json.dump(res, open(f"eval_{a.model}.json", "w"), ensure_ascii=False)
n = len(res); ok = sum(r["ok"] for r in res); nul = sum(1 for r in res if r["pred"] is None)
by = {}
for r in res: by.setdefault(r["src"], [0, 0]); by[r["src"]][0] += 1; by[r["src"]][1] += r["ok"]
print(f"\n{a.model}: heading-acc {ok}/{n} = {ok/n*100:.1f}% | null/parse-fail {nul}")
for s, v in by.items(): print(f"  {s}: {v[1]}/{v[0]} = {v[1]/v[0]*100:.1f}%")
