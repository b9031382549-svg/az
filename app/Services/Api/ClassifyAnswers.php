<?php

namespace App\Services\Api;

use App\Models\ApiRequestName;
use App\Models\ClassificationItem;
use App\Models\ClassificationResult;
use App\Services\Classify\Consensus;
use App\Services\Classify\DecisionSummary;
use Illuminate\Support\Collection;

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

    public function __construct(private readonly DecisionSummary $summary) {}

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
        $grounded = $this->groundedWebAnswers($items, $summaries);

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
            'similarity' => $answered ? $this->similarity($summary['method'], $grounded) : null,
            'kind' => $answered ? ($trash ? 'trash' : $item->kind) : null,
            'units' => $units,
            'status' => $status,
            'method' => $summary['method'],
            'reason' => $summary['reason'],
        ];
    }

    private function similarity(string $method, bool $grounded): ?float
    {
        $value = config('api.similarity.'.($method === 'web_search' && ! $grounded ? 'web_search_ungrounded' : $method));

        return $value === null ? null : (float) $value;
    }

    /**
     * Items whose web-search answer is grounded — confident enough, and its heading among what
     * the deciding mechanisms proposed (the same bar memory promotion uses). Measured 93–96%
     * right, against 34–58% for the rest.
     *
     * @param  Collection<int, ClassificationItem>  $items
     * @param  array<int, array{method: string, reason: string}>  $summaries
     * @return array<int, true> item id => true
     */
    private function groundedWebAnswers(Collection $items, array $summaries): array
    {
        $ids = $items->filter(fn (ClassificationItem $item) => $summaries[$item->id]['method'] === 'web_search')->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }

        $deciding = Consensus::computeAuthoritative(
            (array) config('classify.mechanisms.enabled', []),
            (array) config('classify.mechanisms.shadow', []),
        );
        $min = (float) config('classify.search_resolver.grounded_min_confidence');

        $grounded = [];
        ClassificationResult::whereIn('classification_item_id', $ids)
            ->whereIn('mechanism', [...$deciding, 'search'])
            ->get(['classification_item_id', 'mechanism', 'matched_code', 'confidence'])
            ->groupBy('classification_item_id')
            ->each(function (Collection $results, int $itemId) use ($min, &$grounded) {
                $search = $results->firstWhere('mechanism', 'search');
                if ($search !== null && (float) $search->confidence >= $min
                    && Consensus::headingOverlaps(mb_substr((string) $search->matched_code, 0, 4), $results->where('mechanism', '!=', 'search'))) {
                    $grounded[$itemId] = true;
                }
            });

        return $grounded;
    }
}
