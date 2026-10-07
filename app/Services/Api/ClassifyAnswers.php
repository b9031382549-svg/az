<?php

namespace App\Services\Api;

use App\Models\ApiRequestName;
use App\Models\ClassificationItem;
use App\Services\Classify\AnswerReliability;
use App\Services\Classify\DecisionSummary;

/**
 * The answers of a POST /api/classify request (GET /api/classify/{id}): one entry per distinct
 * name exactly as sent, a page at a time. An entry carries the classifier's answer once the
 * automation is done with its item — `answered` (a code, or trash) or `needs_review` (the
 * automation could not settle it; a human decides later, and the answer then appears here).
 * Until then it is `pending`. `similarity` is how often answers found the same way are right
 * (config api.similarity), not a vector cosine.
 */
class ClassifyAnswers
{
    /** Resolutions that carry an answer. */
    private const ANSWERED = ['agreed', 'ai_resolved', 'confirmed', 'trash'];

    public function __construct(
        private readonly DecisionSummary $summary,
        private readonly AnswerReliability $reliability,
    ) {}

    /** @return array<int, array{name: string, category: ?string, similarity: ?float, kind: ?string, units: array<int, string>, status: string, method: string, reason: string}> */
    public function page(string $batch, int $offset, int $limit): array
    {
        $names = ApiRequestName::with('item')
            ->where('batch', $batch)
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $items = $names->pluck('item')->unique('id')->values();
        $summaries = $this->summary->forItems($items);
        $grounded = $this->reliability->groundedWebAnswers($items, $summaries);

        return $names->map(fn (ApiRequestName $name) => $this->entry(
            $name->name,
            $name->units ?? [],
            $name->item,
            $summaries[$name->item->id],
            isset($grounded[$name->item->id]),
        ))->all();
    }

    /**
     * @param  array<int, string>  $units  the units of measure the caller's lines carried for this name
     * @param  array{method: string, reason: string}  $summary
     * @return array{name: string, category: ?string, similarity: ?float, kind: ?string, units: array<int, string>, status: string, method: string, reason: string}
     */
    private function entry(string $name, array $units, ClassificationItem $item, array $summary, bool $grounded): array
    {
        $status = match (true) {
            $item->answered_at === null => 'pending',
            in_array($item->resolution, self::ANSWERED, true) => 'answered',
            default => 'needs_review',
        };
        $answered = $status === 'answered';
        $trash = $item->resolution === 'trash';

        return [
            'name' => $name,
            'category' => $answered && ! $trash ? $item->final_code : null,
            'similarity' => $answered ? $this->reliability->similarity($summary['method'], $grounded) : null,
            'kind' => $answered ? ($trash ? 'trash' : $item->kind) : null,
            'units' => $units,
            'status' => $status,
            'method' => $summary['method'],
            'reason' => $summary['reason'],
        ];
    }
}
