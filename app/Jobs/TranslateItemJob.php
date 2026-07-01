<?php

namespace App\Jobs;

use App\Services\Translate\ItemTranslator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fills the item-translation dictionary for one uploaded item, in the background
 * and decoupled from classification — a translation failure retries on its own
 * without re-running (or blocking) the classifier. A dictionary hit is a no-op.
 */
class TranslateItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $text) {}

    public function handle(ItemTranslator $translator): void
    {
        $translator->ensure($this->text);
    }
}
