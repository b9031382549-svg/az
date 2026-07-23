<?php

namespace App\Jobs;

use App\Models\ClassificationItem;
use App\Models\TestRun;
use App\Services\Classify\Mechanisms\BrokerDescentMechanism;
use App\Services\Classify\Mechanisms\DirectLlmMechanism;
use App\Services\Classify\Mechanisms\VectorMechanism;
use App\Services\Testing\EndpointOverride;
use App\Services\Testing\TestRunFinalizer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs ONE mechanism for ONE dataset-test item on the SAME 'default' queue as the live
 * classifier (short job, no per-run config override) — so a test run is byte-for-byte
 * the production pipeline, only tagged (test_run_id) and reconciled against the run's
 * chosen mechanism set. The direct analogue of prod's ClassifyMechanismJob.
 *
 * A mechanism error is caught HERE and recorded as an abstaining row, so the job always
 * completes on the SUCCESS path. This matters: adding the search job to the batch (below)
 * must happen before the job's completion is recorded — Laravel records a FAILED job (and
 * can fire the batch's finally) BEFORE the job's own failed() runs, so enlisting the search
 * from failed() would let the scorer fire early and twice. On the success path the add
 * always precedes the completion decrement, so finally fires exactly once, after the search.
 */
class ClassifyTestItemMechanismJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $itemId, public string $mechanism) {}

    public function handle(TestRunFinalizer $finalizer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $item = ClassificationItem::find($this->itemId);
        if ($item === null) {
            return;
        }

        // Idempotent on retry: only run the mechanism if it hasn't already stored a row.
        if (! $item->results()->where('mechanism', $this->mechanism)->exists()) {
            // Optional per-run endpoint override (e.g. a fine-tuned model on a rented
            // GPU): applied ONLY for this mechanism call and restored after, because
            // queue workers are reused and the config must not leak to the next run.
            // A normal run has no override → apply() is a no-op → runs exactly as prod.
            $run = $item->test_run_id !== null ? TestRun::find($item->test_run_id) : null;
            $prior = $run !== null ? EndpointOverride::apply($run) : [];
            try {
                $result = app($this->mechanismClass())->classify((string) $item->source_text);
                $item->results()->updateOrCreate(['mechanism' => $this->mechanism], $result->toRow());
            } catch (Throwable $e) {
                $this->abstain($item, $e); // handled here — never bubble to failed()
            } finally {
                EndpointOverride::restore($prior);
            }
        }

        // Reconcile on the success path; enlist the paid search into THIS batch so scoring
        // waits for it too.
        if ($finalizer->finalize($item)) {
            $this->batch()?->add([new ClassifyTestSearchJob($item->id)]);
        }
    }

    public function failed(Throwable $e): void
    {
        // Only reached on a hard kill (job timeout / OOM), never for a mechanism error.
        // Record the abstention and resolve WITHOUT touching the batch (no search enlist —
        // that would strand the run's "settled?" check and double-fire finally), then
        // re-trigger the (guarded, idempotent) scorer in case this settled the last item.
        $item = ClassificationItem::find($this->itemId);
        if ($item === null) {
            return;
        }

        $this->abstain($item, $e);
        app(TestRunFinalizer::class)->finalize($item, allowSearch: false);
        if ($item->test_run_id !== null) {
            ScoreRunJob::dispatch((int) $item->test_run_id);
        }
    }

    private function abstain(ClassificationItem $item, Throwable $e): void
    {
        // An abstaining row keeps the mechanism's slot in the majority denominator (as
        // prod's ClassifyMechanismJob::failed does) so it can't fake a false agreement.
        $item->results()->updateOrCreate(
            ['mechanism' => $this->mechanism],
            ['status' => 'error', 'matched_code' => null, 'kind' => null, 'explanation' => mb_substr($e->getMessage(), 0, 500)],
        );
    }

    private function mechanismClass(): string
    {
        return match ($this->mechanism) {
            'vector' => VectorMechanism::class,
            'broker' => BrokerDescentMechanism::class,
            'direct' => DirectLlmMechanism::class,
        };
    }
}
