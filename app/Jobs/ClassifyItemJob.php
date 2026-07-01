<?php

namespace App\Jobs;

use App\Models\Classification;
use App\Services\Classify\ClassifierService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Classifies one uploaded line item in the background and stores it in the
 * review queue. One short job per item — restart-safe; idempotent within a batch
 * so a retry never duplicates a row.
 */
class ClassifyItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $text, public string $batch) {}

    public function handle(ClassifierService $classifier): void
    {
        $already = Classification::query()
            ->where('batch', $this->batch)
            ->where('source_text', $this->text)
            ->exists();

        if ($already) {
            return;
        }

        $result = $classifier->classify($this->text);
        $classifier->record($result, $this->batch);

        // Populate the display-translation dictionary out of band (dictionary hit
        // = no-op). Decoupled so a translation failure never fails classification.
        if (config('classify.translate_items', true)) {
            TranslateItemJob::dispatch($this->text);
        }
    }
}
