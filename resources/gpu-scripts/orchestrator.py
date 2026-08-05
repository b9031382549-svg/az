#!/usr/bin/env python3
"""GPU orchestration hands for the A/B zero-downtime retrain loop.

PHP (Illuminate\\Support\\Facades\\Process) is the brain — it holds state in the DB and
decides. This script is the hands: each subcommand does ONE bounded action against Nebius
(via the field-tested `nebius` CLI) or the GPU VM (via system ssh/scp), then prints a
SINGLE JSON line to stdout and exits. Nothing here blocks for hours — long work (boot,
training) is launched with nohup and OBSERVED by polling `status` / `training-status`, so
no long-running PHP job is ever needed (which would trip REDIS_QUEUE_RETRY_AFTER).

Uses subprocess to `nebius` + `ssh`/`scp` (no third-party deps) so it runs unchanged
wherever Horizon runs. Config comes from flags or env: NEBIUS_PROJECT_ID, NEBIUS_SUBNET_ID,
NEBIUS_GOLDEN_SNAPSHOT_ID, plus SSH_KEY / SSH_PUBKEY.

CONTRACT: stdout is JSON only. Success -> {"ok": true, ...}; failure -> {"ok": false,
"error": "..."} with a nonzero exit. Human/diagnostic text goes to stderr.

NOT RUNNABLE END-TO-END until a golden snapshot exists (that bake needs a rented VM — the
paid boundary). The command construction + parsing below is what will drive it then.
"""
import argparse, json, os, subprocess, sys, re, shlex, time

PLATFORM = os.environ.get("GPU_PLATFORM", "gpu-h200-sxm")     # e.g. gpu-h100-sxm
PRESET = os.environ.get("GPU_PRESET", "1gpu-16vcpu-200gb")
MAX_MODEL_LEN = os.environ.get("GPU_MAX_MODEL_LEN", "131072")  # lower for H100's 80GB
MEM_UTIL = os.environ.get("GPU_MEM_UTIL", "0.90")             # 0.95 on H100 (70B leaves ~1.6GB KV at 0.90)
SSH_OPTS = ["-o", "StrictHostKeyChecking=no", "-o", "UserKnownHostsFile=/dev/null",
            "-o", "ConnectTimeout=10", "-o", "BatchMode=yes"]


def out(obj, code=0):
    """Emit the single JSON result line and exit."""
    print(json.dumps(obj))
    sys.exit(code)


def fail(msg):
    out({"ok": False, "error": str(msg)}, code=1)


def sh(cmd, timeout=60, input_data=None):
    """Run a command, return (rc, stdout, stderr). Never raises on nonzero."""
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, input=input_data)
        return p.returncode, p.stdout, p.stderr
    except subprocess.TimeoutExpired:
        return 124, "", f"timeout after {timeout}s"


def nebius(args, timeout=120):
    """Run `nebius <args> --format json`, return parsed JSON (best effort). Raises on failure."""
    rc, so, se = sh(["nebius"] + args + ["--format", "json"], timeout=timeout)
    if rc != 0:
        raise RuntimeError(f"nebius {' '.join(args)} failed ({rc}): {se.strip() or so.strip()}")
    try:
        return json.loads(so) if so.strip() else {}
    except json.JSONDecodeError:
        # Some subcommands aren't wired for JSON; fall back to scraping ids/status.
        return {"_raw": so}


def find_id(blob, prefix):
    """Pull the first `<prefix>-xxxx` id out of a JSON blob or raw text."""
    text = blob.get("_raw") if isinstance(blob, dict) and "_raw" in blob else json.dumps(blob)
    m = re.search(prefix + r"-[a-z0-9]+", text)
    return m.group(0) if m else None


def ssh(ip, remote_cmd, timeout=60, key=None):
    key = key or os.environ.get("SSH_KEY", os.path.expanduser("~/.ssh/id_ed25519"))
    cmd = ["ssh"] + SSH_OPTS + ["-i", key, f"ubuntu@{ip}", remote_cmd]
    return sh(cmd, timeout=timeout)


def scp(src, dst, timeout=1800, key=None, recursive=False):
    key = key or os.environ.get("SSH_KEY", os.path.expanduser("~/.ssh/id_ed25519"))
    cmd = ["scp"] + SSH_OPTS + ["-i", key]
    if recursive:
        cmd.append("-r")
    cmd += [src, dst]
    return sh(cmd, timeout=timeout)


# --- subcommands -----------------------------------------------------------------

def cmd_provision(a):
    """Create an H200 whose boot disk is cloned from the golden SNAPSHOT (which already
    holds base model + venv + scripts). Returns the instance id immediately; boot/serve
    readiness is polled by `status`. A snapshot (not a custom image) because the tenant's
    image quota is 0 — snapshot quota is available and the boot disk sources straight from
    it, so there is no secondary data disk and no per-VM 130 GB download."""
    proj = a.project or os.environ["NEBIUS_PROJECT_ID"]
    subnet = a.subnet or os.environ["NEBIUS_SUBNET_ID"]
    snapshot = a.snapshot or os.environ["NEBIUS_GOLDEN_SNAPSHOT_ID"]
    pub = a.ssh_pubkey or os.environ.get("SSH_PUBKEY", os.path.expanduser("~/.ssh/id_ed25519.pub"))
    pubkey = open(pub).read().strip()
    name = f"az-gpu-{a.slot.lower()}"

    cloud_init = "#cloud-config\nssh_authorized_keys:\n  - " + pubkey
    nics = json.dumps([{"name": "eth0", "subnet_id": subnet,
                        "ip_address": {}, "public_ip_address": {"static": False}}])
    # --async: return as soon as the instance resource exists, WITHOUT waiting for it to
    # reach RUNNING. A synchronous create blocks ~3-4min for a snapshot-boot H200, which
    # (a) would exceed the queue's retry_after and re-dispatch a DUPLICATE provision, and
    # (b) leaves a long window where a stray concurrent op aborts the create. Readiness
    # (auto-start → RUNNING → public IP → SSH) is driven by `status` polling instead.
    try:
        res = nebius(["compute", "instance", "create",
                      "--name", name, "--parent-id", proj,
                      "--resources-platform", PLATFORM, "--resources-preset", PRESET,
                      "--boot-disk-attach-mode", "read_write",
                      "--boot-disk-managed-disk-name", f"{name}-boot",
                      "--boot-disk-managed-disk-type", "network_ssd",
                      "--boot-disk-managed-disk-source-snapshot-id", snapshot,
                      "--boot-disk-managed-disk-size-gibibytes", "300",
                      "--network-interfaces", nics,
                      "--cloud-init-user-data", cloud_init, "--async"], timeout=90)
    except RuntimeError as e:
        fail(e)
    iid = find_id(res, "computeinstance")
    if not iid:
        # Fallback: the async operation payload didn't surface the id — resolve by name.
        try:
            lst = nebius(["compute", "instance", "list", "--parent-id", proj])
            text = lst.get("_raw", json.dumps(lst))
            m = re.search(r'(computeinstance-[a-z0-9]+)(?:(?!computeinstance-).)*?"name"\s*[:=]\s*"'
                          + re.escape(name) + r'"', text, re.S)
            iid = m.group(1) if m else None
        except RuntimeError:
            iid = None
    if not iid:
        fail(f"instance created but no id parsed from: {json.dumps(res)[:400]}")
    out({"ok": True, "instance_id": iid})


def cmd_start(a):
    """Start a STOPPED instance (async). Used by the poller ONLY when status shows STOPPED,
    never while it's already STARTING/RUNNING — issuing start over an in-flight op aborts it."""
    try:
        nebius(["compute", "instance", "start", "--id", a.instance, "--async"], timeout=60)
    except RuntimeError as e:
        fail(e)
    out({"ok": True})


def _instance_view(iid):
    res = nebius(["compute", "instance", "get", "--id", iid])
    text = res.get("_raw") if "_raw" in res else json.dumps(res)
    # Instance run state (top-level status.state); take the LAST match so a disk's own
    # "state" earlier in the payload can't shadow the instance state.
    status = None
    for m in re.finditer(r'"?state"?\s*[:=]\s*"?([A-Z_]+)', text):
        status = m.group(1)
    # PUBLIC ip only — the private ip_address (10.0.0.x) is unreachable from where the app
    # runs, and it appears first in the payload, so a naive "first address" grabs the wrong
    # one. Match the address INSIDE the public_ip_address block; empty {} → no IP (stopped).
    ip = None
    m = re.search(r'"public_ip_address"\s*[:=]\s*\{[^{}]*?"address"\s*[:=]\s*"(\d+\.\d+\.\d+\.\d+)', text)
    if m:
        ip = m.group(1)
    return status, ip, text


def cmd_status(a):
    """One bounded health probe: Nebius instance state + (once it has an IP) SSH reachable
    + vLLM /v1/models ready with which model names. The poller maps this onto the DB state
    machine (booting -> deploying_adapter -> serving)."""
    try:
        status, ip, _ = _instance_view(a.instance)
    except RuntimeError as e:
        fail(e)
    r = {"ok": True, "nebius_status": status, "ip": ip, "ssh_ok": False,
         "vllm_ready": False, "models": []}
    if ip:
        rc, _, _ = ssh(ip, "true", timeout=12, key=a.ssh_key)
        r["ssh_ok"] = rc == 0
        if r["ssh_ok"]:
            # vLLM serves with --api-key, so /v1/models needs the bearer token too.
            auth = '-H "Authorization: Bearer '+a.api_key+'"' if getattr(a, "api_key", None) else ""
            rc, so, _ = ssh(ip, f"curl -s --max-time 8 {auth} http://127.0.0.1:8000/v1/models || true", timeout=20, key=a.ssh_key)
            try:
                models = [m.get("id") for m in (json.loads(so).get("data") or [])]
                r["models"] = [m for m in models if m]
                r["vllm_ready"] = len(r["models"]) > 0
            except (json.JSONDecodeError, AttributeError):
                pass
    out(r)


def _vllm_serve(api_key, lora_path=None):
    """Build the (nohup, background) vLLM serve command. base is aliased to its
    Token-Factory name too, so a non-overridden gpu:base stage resolves without a 404.
    max-model-len 131072 because the broker prompt is ~33k tokens (measured). With a
    lora_path the fine-tuned adapter is additionally served as `xif`."""
    # Kill any prior vLLM by PORT, not `pkill -f 'vllm serve'` — that pattern also matches
    # THIS launcher's own command line (it contains "vllm serve") and kills its own shell
    # before vLLM starts (ssh 255, no log). fuser -k 8000/tcp only hits the process holding
    # the serve port, never the launcher.
    cmd = ("fuser -k 8000/tcp 2>/dev/null; sleep 3; "
           "nohup /home/ubuntu/venv/bin/vllm serve /home/ubuntu/llama70 "
           "--served-model-name base meta-llama/Llama-3.3-70B-Instruct "
           f"--quantization fp8 --max-model-len {MAX_MODEL_LEN} --gpu-memory-utilization {MEM_UTIL} "
           "--enforce-eager --host 0.0.0.0 --port 8000 "
           f"--api-key {shlex.quote(api_key)}")
    if lora_path:
        cmd += f" --enable-lora --lora-modules xif={lora_path} --max-lora-rank 16"
    # `< /dev/null` + disown is essential: without detaching stdin the backgrounded vLLM
    # keeps the ssh channel open, so ssh returns 255 (a FALSE failure) even though the
    # process launched fine. The trailing `disown` makes the shell exit 0 cleanly.
    return cmd + " < /dev/null > /home/ubuntu/vllm.log 2>&1 & disown"


def cmd_serve_base(a):
    """Start vLLM serving ONLY the base model (no adapter, no training) — the simplest
    "just turn it on" path to exercise provisioning + request routing. Launched via nohup;
    readiness via `status` (models will list `base`)."""
    rc, _, se = ssh(a.ip, _vllm_serve(a.api_key), timeout=120, key=a.ssh_key)
    if rc != 0:
        fail(f"serve-base failed: {se.strip()}")
    out({"ok": True})


def cmd_deploy_adapter(a):
    """Push the adapter tar (durable master, held on the VPS where this runs) to the
    server and (re)start vLLM serving base + adapter (as `xif`). Launched via nohup —
    returns at once; readiness via `status`."""
    remote = "/home/ubuntu/adapter"
    rc, _, se = scp(a.adapter_local, f"ubuntu@{a.ip}:/home/ubuntu/adapter.tar", timeout=1800, key=a.ssh_key)
    if rc != 0:
        fail(f"scp adapter to server failed: {se.strip()}")
    unpack = f"rm -rf {remote} && mkdir -p {remote} && tar -xf /home/ubuntu/adapter.tar -C {remote}"
    rc, _, se = ssh(a.ip, f"{unpack}; {_vllm_serve(a.api_key, remote)}", timeout=300, key=a.ssh_key)
    if rc != 0:
        fail(f"deploy failed: {se.strip()}")
    out({"ok": True})


def cmd_start_training(a):
    """Upload the corpus, launch train_lora.py under nohup (writes the heartbeat JSON),
    return immediately. Progress is read by `training-status`. vLLM must be stopped first
    (training needs the full GPU)."""
    scp(a.data, f"ubuntu@{a.ip}:/home/ubuntu/train.jsonl", timeout=600, key=a.ssh_key)
    scp(os.path.join(os.path.dirname(__file__), "train_lora.py"),
        f"ubuntu@{a.ip}:/home/ubuntu/train_lora.py", timeout=120, key=a.ssh_key)
    hb = "/home/ubuntu/heartbeat.json"
    # The bootstrap — free the GPU, apt-install the build deps ONLY if the snapshot lacks them,
    # then run the trainer — executes as ONE detached background job (nohup … & disown) so the
    # launch ssh returns in seconds. NOTHING slow may sit on the ssh-timeout path: the apt
    # fallback once ran a COLD install here (deps weren't baked → `dpkg -s` fell through) and
    # blew past the 120s budget, failing the whole retrain. Backgrounding it means a cold apt
    # can take as long as it needs; progress is observed via the heartbeat + train.log by
    # training-status. Free the GPU by PORT (`fuser -k 8000/tcp`), NOT `pkill -f 'vllm serve'`
    # (that matches this launcher's own command line → suicide). SAVED is touched only on a
    # clean trainer exit; a nonzero exit leaves no SAVED → training-status reports "failed".
    bootstrap = (
        "fuser -k 8000/tcp 2>/dev/null; sleep 3; "
        "rm -f /home/ubuntu/heartbeat.json /home/ubuntu/SAVED; "
        "dpkg -s build-essential python3-dev >/dev/null 2>&1 || "
        "sudo apt-get -o DPkg::Lock::Timeout=60 install -y -qq python3-dev build-essential >/dev/null 2>&1 || true; "
        f"/home/ubuntu/venv/bin/python /home/ubuntu/train_lora.py "
        f"--data /home/ubuntu/train.jsonl --base /home/ubuntu/llama70 "
        f"--out /home/ubuntu/adapter_new --epochs {a.epochs} --heartbeat {hb} "
        "&& touch /home/ubuntu/SAVED"
    )
    launch = f"nohup bash -c {shlex.quote(bootstrap)} < /dev/null > /home/ubuntu/train.log 2>&1 & disown"
    rc, _, se = ssh(a.ip, launch, timeout=60, key=a.ssh_key)
    if rc != 0:
        fail(f"train launch failed: {se.strip()}")
    out({"ok": True, "heartbeat": hb})


def cmd_training_status(a):
    """Read the heartbeat JSON + whether the trainer process is alive + the SAVED marker.
    Distinguishes: still-training / done(saved) / died(no process, no SAVED = failure)."""
    rc, so, _ = ssh(a.ip, "cat /home/ubuntu/heartbeat.json 2>/dev/null || true", timeout=20, key=a.ssh_key)
    hb = {}
    try:
        hb = json.loads(so) if so.strip() else {}
    except json.JSONDecodeError:
        hb = {}
    rc2, so2, _ = ssh(a.ip,
                      "pgrep -f train_lora.py >/dev/null && echo ALIVE; "
                      "[ -f /home/ubuntu/SAVED ] && echo SAVED; true",
                      timeout=20, key=a.ssh_key)
    alive = "ALIVE" in so2
    saved = "SAVED" in so2
    # SAVED wins over ALIVE: once the adapter is on disk + the marker is set, training is
    # DONE — even if the python process lingers (unsloth/torch/NCCL teardown can hang at exit
    # after save_pretrained; a live-but-saved process must not pin the run in 'training').
    state = "done" if saved else ("training" if alive else "failed")
    out({"ok": True, "state": state, "process_alive": alive, "saved": saved,
         "step": hb.get("step"), "total_steps": hb.get("total_steps"),
         "loss": hb.get("loss"), "eta_seconds": hb.get("eta_seconds")})


def cmd_fetch_adapter(a):
    """Tar the freshly-trained adapter ON the VM, pull it to the VPS path (durable master),
    return its sha256. This is the asset that survives everything; the DB just points at it."""
    rc, _, se = ssh(a.ip, "cd /home/ubuntu && tar -cf adapter_new.tar -C adapter_new .", timeout=300, key=a.ssh_key)
    if rc != 0:
        fail(f"remote tar failed: {se.strip()}")
    os.makedirs(os.path.dirname(a.vps_path), exist_ok=True)
    rc, _, se = scp(f"ubuntu@{a.ip}:/home/ubuntu/adapter_new.tar", a.vps_path, timeout=1800, key=a.ssh_key)
    if rc != 0:
        fail(f"scp adapter home failed: {se.strip()}")
    rc, so, _ = sh(["sha256sum", a.vps_path], timeout=120)
    sha = so.split()[0] if rc == 0 and so else None
    out({"ok": True, "vps_path": a.vps_path, "sha256": sha})


def _confirm_gone(iid, attempts=4):
    """Is the instance actually gone? A transient CLI/DNS failure (e.g. the worker's docker
    resolver blipping) can break `delete`'s wait-for-op even though the delete op was created
    and completes server-side. So don't trust the delete error alone — GET the instance and
    retry through transient failures: NotFound = truly gone (success); a clean GET = still
    there (real failure); never-confirmed = conservatively False (surface the warning)."""
    for i in range(attempts):
        try:
            _instance_view(iid)
            return False  # GET succeeded → instance still exists → the delete really failed
        except RuntimeError as e:
            if "not found" in str(e).lower():
                return True  # confirmed gone
            if i < attempts - 1:
                time.sleep(5)  # transient (DNS/Unavailable) → give the op + resolver a moment
    return False


def cmd_destroy(a):
    """Delete the instance (boot disk auto-deletes with it). The golden snapshot and the
    VPS adapters survive — nothing durable lives on the VM."""
    try:
        nebius(["compute", "instance", "delete", "--id", a.instance])
    except RuntimeError as e:
        # "not found" = already gone (idempotent). Any other error might be a transient blip
        # DURING the op-wait while the delete actually succeeds — verify before crying failure,
        # so a flaky DNS lookup can't leave a scary permanent warning on an off slot.
        if "not found" not in str(e).lower() and not _confirm_gone(a.instance):
            fail(e)
    out({"ok": True, "instance_id": a.instance, "destroyed": True})


def main():
    ap = argparse.ArgumentParser(description="A/B GPU orchestration hands (JSON out).")
    sub = ap.add_subparsers(dest="cmd", required=True)

    p = sub.add_parser("provision"); p.add_argument("--slot", required=True)
    p.add_argument("--role", default="serving"); p.add_argument("--project"); p.add_argument("--subnet")
    p.add_argument("--snapshot"); p.add_argument("--ssh-pubkey"); p.set_defaults(fn=cmd_provision)

    p = sub.add_parser("status"); p.add_argument("--instance", required=True)
    p.add_argument("--ssh-key"); p.add_argument("--api-key", dest="api_key"); p.set_defaults(fn=cmd_status)

    p = sub.add_parser("start"); p.add_argument("--instance", required=True); p.set_defaults(fn=cmd_start)

    p = sub.add_parser("serve-base"); p.add_argument("--ip", required=True)
    p.add_argument("--api-key", required=True); p.add_argument("--ssh-key"); p.set_defaults(fn=cmd_serve_base)

    p = sub.add_parser("deploy-adapter"); p.add_argument("--ip", required=True)
    p.add_argument("--api-key", required=True); p.add_argument("--adapter-local", required=True, dest="adapter_local")
    p.add_argument("--ssh-key"); p.set_defaults(fn=cmd_deploy_adapter)

    p = sub.add_parser("start-training"); p.add_argument("--ip", required=True)
    p.add_argument("--data", required=True); p.add_argument("--epochs", default="1")
    p.add_argument("--ssh-key"); p.set_defaults(fn=cmd_start_training)

    p = sub.add_parser("training-status"); p.add_argument("--ip", required=True)
    p.add_argument("--ssh-key"); p.set_defaults(fn=cmd_training_status)

    p = sub.add_parser("fetch-adapter"); p.add_argument("--ip", required=True)
    p.add_argument("--vps-path", required=True, dest="vps_path")
    p.add_argument("--ssh-key"); p.set_defaults(fn=cmd_fetch_adapter)

    p = sub.add_parser("destroy"); p.add_argument("--instance", required=True); p.set_defaults(fn=cmd_destroy)

    a = ap.parse_args()
    try:
        a.fn(a)
    except KeyError as e:
        fail(f"missing required env var: {e}")
    except Exception as e:  # noqa: BLE001 — any failure must still emit JSON for the PHP caller
        fail(e)


if __name__ == "__main__":
    main()
