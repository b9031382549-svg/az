<?php

namespace App\Services\Classify;

use App\Models\ClassificationItem;
use App\Models\ClassificationResult;

/**
 * The sorter step of the queue — after the answer cache and the TrashFilter rules, before
 * the AI (run by SortItemsJob). One call to the sorter service for the names still left;
 * each item gets a 'sorter' trace row (its verdict + the three probabilities, read later by
 * Consensus for services), and a confident 'trash' verdict settles the item on the spot
 * (rule 'sorter', the same 'trash' row the rules write, so a reviewer's "not trash" works the
 * same way).
 */
final class Sorter
{
    public function __construct(
        private readonly SorterClient $client,
        private readonly TrashFilter $trash,
    ) {}

    public function enabled(): bool
    {
        return $this->client->enabled();
    }

    /**
     * The item's verdict — the stored 'sorter' row, or asked for now (without settling trash)
     * when the step had no verdict for it (the service was down at that moment, or a test run,
     * which has no sorter step). Null when the sorter is off or unavailable.
     */
    public function verdict(ClassificationItem $item): ?ClassificationResult
    {
        $row = $item->results()->where('mechanism', 'sorter')->first();
        if ($row !== null || ! $this->enabled()) {
            return $row;
        }
        // Asked from inside a mechanism job: a short wait, never the job's whole budget.
        $this->screen([$item], settleTrash: false, timeout: 10);

        return $item->results()->where('mechanism', 'sorter')->first();
    }

    /**
     * @param  array<int, ClassificationItem>  $items  names the cache and the rules did not settle
     * @param  bool  $settleTrash  false = store the verdicts only (no trash settled)
     * @param  int|null  $timeout  seconds to wait for the service (default classify.sorter.timeout)
     * @return array<int, ClassificationItem> the items that still go to the AI
     */
    public function screen(array $items, bool $settleTrash = true, ?int $timeout = null): array
    {
        $items = array_values($items);
        if ($items === [] || ! $this->client->enabled()) {
            return $items;
        }

        $probs = $this->client->classify(array_map(fn (ClassificationItem $i) => (string) $i->source_text, $items), $timeout);
        if ($probs === null) {
            return $items; // the service is down — the pipeline runs as it would without it
        }

        $threshold = (float) config('classify.sorter.trash_threshold');
        $rest = [];
        foreach ($items as $k => $item) {
            $p = $probs[$k] ?? null;
            if ($p === null || $p === []) {
                $rest[] = $item;

                continue;
            }
            arsort($p);
            $label = (string) array_key_first($p);

            $item->results()->updateOrCreate(
                ['mechanism' => 'sorter'],
                [
                    'matched_code' => null,
                    'catalog_id' => null,
                    'kind' => $label,
                    'status' => 'sorted',
                    'confidence' => round($p[$label], 4),
                    'candidates' => [],
                    'explanation' => 'Sorter: '.implode(', ', array_map(
                        fn ($l, $v) => $l.' '.round(100 * $v, 1).'%', array_keys($p), $p)).'.',
                    'trace' => ['probs' => array_map(fn ($v) => round($v, 6), $p), 'trash_threshold' => $threshold],
                    'model' => 'sorter',
                ],
            );

            $pTrash = (float) ($p['trash'] ?? 0.0);
            if ($settleTrash && $pTrash >= $threshold && $this->trash->settle($item, 'sorter', ['p' => round($pTrash, 6)])) {
                continue;
            }
            $rest[] = $item;
        }

        return $rest;
    }
}
