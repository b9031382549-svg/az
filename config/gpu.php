<?php

return [
    /*
    | A/B GPU orchestration — two inference slots in a weekly zero-downtime retrain loop.
    | The app is the brain (state in gpu_servers / finetune_runs / finetune_adapters); a
    | Python orchestrator (resources/gpu-scripts/orchestrator.py) is the hands, driven via
    | Illuminate Process. Nebius is the current provider; owned fixed servers later just
    | fill a slot's connection columns and skip provisioning entirely.
    |
    | Everything here is off by default: with no golden snapshot and no active slot, the
    | "gpu:" resolver falls back to Token Factory (base model) and nothing provisions.
    */

    // Runner for the orchestrator subprocess.
    'python_bin' => env('GPU_PYTHON_BIN', 'python3'),
    'orchestrator' => base_path('resources/gpu-scripts/orchestrator.py'),
    'train_data_default' => env('GPU_TRAIN_DATA', storage_path('app/finetune')),

    // Nebius resource ids (see research-data/finetune PREP_STATE.env). golden_snapshot is
    // the ONE disk-snapshot each slot's boot disk is cloned from (base model + venv +
    // scripts baked in) — empty until the bake step (which needs a rented VM = the paid
    // boundary), and provisioning refuses to run without it. A snapshot (not a custom
    // image) because the tenant's image quota is 0 — snapshot quota is available, and
    // `instance create --boot-disk-managed-disk-source-snapshot-id` boots straight from it.
    'nebius' => [
        'project_id' => env('NEBIUS_PROJECT_ID', 'project-e00dcergpr00zj0gm1f3kj'),
        'subnet_id' => env('NEBIUS_SUBNET_ID', 'vpcsubnet-e00t14gt0y9qzaa84m'),
        'golden_snapshot_id' => env('NEBIUS_GOLDEN_SNAPSHOT_ID'),
    ],

    // GPU tier the slots provision on. H200 (141GB) fits 70B fp8 with a full 131k context
    // (the broker needs ~33k). H100 (80GB) is cheaper + usually more available, but 70B fp8
    // leaves only ~10GB for the KV cache — set GPU_MAX_MODEL_LEN low (e.g. 8192) so it fits;
    // fine for switch/routing/training tests, too small for the broker's full prompts.
    // Change tier by env alone — no orchestrator edit.
    'platform' => env('GPU_PLATFORM', 'gpu-h200-sxm'),   // e.g. gpu-h100-sxm
    'preset' => env('GPU_PRESET', '1gpu-16vcpu-200gb'),
    'max_model_len' => (int) env('GPU_MAX_MODEL_LEN', 131072),
    // vLLM GPU memory budget. 70B fp8 weights are ~68GB, so on an 80GB H100 the default 0.90
    // (72GB) leaves only ~1.6GB for the KV cache — engine init fails. 0.95 (~76GB) frees
    // enough. H200 (141GB) has room to spare either way.
    'gpu_mem_util' => (float) env('GPU_MEM_UTIL', 0.90),

    // SSH identity the orchestrator uses to reach the GPU VMs (its pubkey is injected via
    // cloud-init at provision time). Lives wherever Horizon runs.
    'ssh_key' => env('GPU_SSH_KEY', env('HOME').'/.ssh/id_ed25519'),
    'ssh_pubkey' => env('GPU_SSH_PUBKEY', env('HOME').'/.ssh/id_ed25519.pub'),

    // Durable adapter store — the master .tar of every trained adapter. Survives deploys
    // and VM teardown; the DB only points at it. Mirror of the VPS /opt/az-assets pattern.
    'adapter_dir' => env('GPU_ADAPTER_DIR', storage_path('app/finetune/adapters')),

    // "Forgot" safety only (meaningful for rented Nebius, retired with owned servers):
    // destroy a serving slot after this idle gap, or ANY slot past the hard ceiling.
    'autostop_idle_minutes' => (int) env('GPU_AUTOSTOP_IDLE_MINUTES', 120),
    'hard_ceiling_hours' => (int) env('GPU_HARD_CEILING_HOURS', 24),

    // Eval-gate: the held-out dataset a freshly-trained adapter is scored on (a TestRun),
    // so the switch decision shows "new 86.5% vs current 82.9%". Null → eval-gate skipped
    // (the run still completes; the switch is then taken on trust). Points at the
    // gold-split/test dataset by test_datasets.id.
    'eval_dataset_id' => env('GPU_EVAL_DATASET_ID') !== null ? (int) env('GPU_EVAL_DATASET_ID') : null,

    // Default training hyperparameters (from the field-tested 45k run).
    'train' => [
        'epochs' => (float) env('GPU_TRAIN_EPOCHS', 1),
        'cap' => (int) env('GPU_TRAIN_CAP', 200),   // per-heading cap for finetune:export-examples
    ],

    // Per-orchestrator-call subprocess timeout (seconds). Every subcommand is bounded
    // (launch-and-return or a health probe) — none blocks for training-length time.
    'process_timeout' => (int) env('GPU_PROCESS_TIMEOUT', 300),
];
