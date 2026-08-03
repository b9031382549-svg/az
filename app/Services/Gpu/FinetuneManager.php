<?php

namespace App\Services\Gpu;

use App\Models\FinetuneAdapter;
use App\Models\FinetuneRun;
use App\Models\GpuServer;
use App\Models\TestDataset;
use App\Models\TestRun;
use App\Services\Testing\TestRunner;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

/**
 * The retrain-cycle state machine, advanced one bounded step per gpu:tick:
 *   pending → training → fetching → evaluating → done   (or → failed)
 *
 * Key design point (the whole reason for two slots): the TRAINING slot BECOMES the new
 * serving slot. After training it deploys its own fresh adapter, gets eval-scored, and
 * parks in `ready_to_switch` — so the human's switch is a pointer flip onto an already-warm
 * server, never a reload on the live one. Train-from-scratch each cycle on the cumulative
 * answer_cache corpus (see [[az-ab-gpu-orchestration]]).
 */
class FinetuneManager
{
    public function __construct(
        private readonly GpuOrchestrator $orchestrator,
        private readonly GpuServerManager $servers,
        private readonly TestRunner $testRunner,
    ) {}

    /**
     * Begin a cycle on a free slot: export the corpus fresh from answer_cache, record the
     * run, and provision the slot as a training worker. poll() takes it from there.
     */
    public function start(GpuServer $slot): FinetuneRun
    {
        $run = FinetuneRun::create([
            'gpu_server_id' => $slot->id,
            'status' => FinetuneRun::STATUS_EXPORTING,
            'hyperparams' => ['epochs' => (float) config('gpu.train.epochs'), 'rank' => 16, 'lr' => 2e-4],
            'started_at' => now(),
        ]);

        [$path, $rows] = $this->exportCorpus($run->id);
        $run->update(['corpus_path' => $path, 'corpus_rows' => $rows, 'status' => FinetuneRun::STATUS_PENDING]);

        $slot->current_run_id = $run->id;
        $slot->save();
        $this->servers->provisionTraining($slot);

        return $run;
    }

    /** Advance one run by a single bounded step. Errors park the run in `failed`. */
    public function poll(FinetuneRun $run): void
    {
        $server = $run->server;
        if (! $server) {
            return;
        }
        try {
            match ($run->status) {
                FinetuneRun::STATUS_PENDING => $this->onPending($run, $server),
                FinetuneRun::STATUS_TRAINING => $this->onTraining($run, $server),
                FinetuneRun::STATUS_FETCHING => $this->onFetching($run, $server),
                FinetuneRun::STATUS_EVALUATING => $this->onEvaluating($run, $server),
                default => null,
            };
        } catch (Throwable $e) {
            $run->update(['status' => FinetuneRun::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 480), 'finished_at' => now()]);
        }
    }

    /** Wait for the training slot to be SSH-reachable, then launch training. */
    private function onPending(FinetuneRun $run, GpuServer $server): void
    {
        if (! $server->instance_id) {
            return;
        }
        $st = $this->orchestrator->status($server->instance_id);
        if (! ($st['ssh_ok'] ?? false)) {
            return; // still booting — try again next tick
        }
        $server->ip = $st['ip'] ?? $server->ip;
        $this->orchestrator->startTraining($server->ip, (string) $run->corpus_path, (float) config('gpu.train.epochs'));
        $server->status = GpuServer::STATUS_TRAINING;
        $server->status_detail = 'Training run #'.$run->id;
        $server->save();
        $run->update(['status' => FinetuneRun::STATUS_TRAINING]);
    }

    /** Mirror the remote heartbeat into the run; on SAVED move to fetch, on death fail. */
    private function onTraining(FinetuneRun $run, GpuServer $server): void
    {
        $ts = $this->orchestrator->trainingStatus($server->ip);
        $run->update([
            'step' => $ts['step'] ?? $run->step,
            'total_steps' => $ts['total_steps'] ?? $run->total_steps,
            'loss' => $ts['loss'] ?? $run->loss,
            'eta_seconds' => $ts['eta_seconds'] ?? $run->eta_seconds,
        ]);
        if (($ts['state'] ?? '') === 'done') {
            $run->update(['status' => FinetuneRun::STATUS_FETCHING]);
        } elseif (($ts['state'] ?? '') === 'failed') {
            throw new RuntimeException('Training process died before saving the adapter (see train.log on the VM).');
        }
    }

    /** Pull the adapter to the VPS (durable), register it, deploy it back onto THIS slot. */
    private function onFetching(FinetuneRun $run, GpuServer $server): void
    {
        $version = now()->format('Y-m-d').'-'.strtolower($server->slot).'-'.$run->id;
        $vpsPath = rtrim((string) config('gpu.adapter_dir'), '/').'/'.$version.'.tar';

        $res = $this->orchestrator->fetchAdapter($server->ip, $vpsPath);

        $adapter = FinetuneAdapter::create([
            'version' => $version,
            'vps_path' => $res['vps_path'] ?? $vpsPath,
            'sha256' => $res['sha256'] ?? null,
            'base_model' => 'meta-llama/Llama-3.3-70B-Instruct',
            'corpus_path' => $run->corpus_path,
            'corpus_rows' => $run->corpus_rows,
            'trained_from_run_id' => $run->id,
        ]);
        $run->update(['adapter_id' => $adapter->id]);

        // The training slot becomes the new serving candidate: deploy its own fresh adapter.
        $server->served_adapter_id = $adapter->id;
        $server->role = 'serving';
        $this->orchestrator->deployAdapter($server->ip, $server->api_key, $adapter->vps_path);
        $server->status = GpuServer::STATUS_DEPLOYING_ADAPTER;
        $server->status_detail = 'Deploying fresh adapter '.$version.' for eval';
        $server->save();

        $run->update(['status' => FinetuneRun::STATUS_EVALUATING]);
    }

    /**
     * Wait for the slot to actually serve, launch the eval TestRun once against it, then
     * read Direct/overall accuracy back onto the adapter and park the slot ready_to_switch.
     */
    private function onEvaluating(FinetuneRun $run, GpuServer $server): void
    {
        if ($server->status !== GpuServer::STATUS_SERVING) {
            $this->servers->poll($server);          // push deploy → serving

            return;
        }

        $datasetId = config('gpu.eval_dataset_id');
        if ($datasetId === null) {
            $this->finish($run, $server);           // no eval configured — trust the run

            return;
        }

        if ($run->eval_test_run_id === null) {
            $dataset = TestDataset::find($datasetId);
            if (! $dataset) {
                $this->finish($run, $server);       // misconfigured dataset — don't block

                return;
            }
            $testRun = $this->testRunner->launch(
                $dataset,
                'Eval-gate '.($run->adapter?->version ?? 'run#'.$run->id),
                ['enabled' => ['vector', 'broker', 'direct'], 'cache' => false, 'search' => false],
                ['model' => 'xif', 'expand_model' => 'base', 'base_url' => $server->base_url, 'api_key' => $server->api_key],
            );
            $run->update(['eval_test_run_id' => $testRun->id]);

            return; // scored asynchronously — read it next tick
        }

        $testRun = TestRun::find($run->eval_test_run_id);
        if (! $testRun || $testRun->status !== 'done') {
            return; // still scoring
        }
        $cols = (array) ($testRun->accuracy['columns'] ?? []);
        $run->adapter?->update([
            'direct_acc' => $this->pct($cols['direct'] ?? null),
            'overall_acc' => $this->pct($cols['overall'] ?? null),
        ]);
        $this->finish($run, $server);
    }

    private function finish(FinetuneRun $run, GpuServer $server): void
    {
        $run->update(['status' => FinetuneRun::STATUS_DONE, 'finished_at' => now()]);
        $server->status = GpuServer::STATUS_READY_TO_SWITCH;
        $server->status_detail = 'Trained + evaluated — ready to switch';
        $server->save();
    }

    /** @return array{0: string, 1: int} [path, rows] */
    private function exportCorpus(int $runId): array
    {
        $path = rtrim((string) config('gpu.train_data_default'), '/')."/train-run-{$runId}.jsonl";
        $args = ['--output' => $path, '--cap' => (int) config('gpu.train.cap')];
        // A small max_examples (env GPU_TRAIN_MAX_EXAMPLES) trains on a tiny sample — for a
        // fast, cheap TEST cycle. 0 = the full production corpus.
        if (($limit = (int) config('gpu.train.max_examples')) > 0) {
            $args['--limit'] = $limit;
        }
        Artisan::call('finetune:export-examples', $args);
        $rows = is_file($path) ? (int) substr_count((string) file_get_contents($path), "\n") : 0;

        return [$path, $rows];
    }

    /** {ran, correct} → integer percent, or null. */
    private function pct(?array $bucket): ?int
    {
        $ran = (int) ($bucket['ran'] ?? 0);

        return $ran > 0 ? (int) round(100 * (int) ($bucket['correct'] ?? 0) / $ran) : null;
    }
}
