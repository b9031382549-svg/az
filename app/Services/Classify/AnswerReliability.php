<?php

namespace App\Services\Classify;

use App\Models\ClassificationItem;
use App\Models\ClassificationResult;
use Illuminate\Support\Collection;

/**
 * How far to trust an answer: how often answers found the same way (DecisionSummary's method)
 * are right — our measured precision at the 4-digit heading (config api.similarity), not a
 * vector cosine. One source for the API's `similarity` and the line export's reliability.
 */
class AnswerReliability
{
    /** The measured precision of $method; a web-search answer counts as grounded or not. */
    public function similarity(string $method, bool $grounded): ?float
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
     * @param  array<int, array{method: string, reason?: string}>  $summaries  item id => its method
     * @return array<int, true> item id => true
     */
    public function groundedWebAnswers(Collection $items, array $summaries): array
    {
        $ids = $items->filter(fn (ClassificationItem $item) => ($summaries[$item->id]['method'] ?? null) === 'web_search')->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }

        $deciding = Consensus::computeAuthoritative(
            (array) config('classify.mechanisms.enabled', []),
            (array) config('classify.mechanisms.shadow', []),
        );
        $min = (float) config('classify.search_resolver.grounded_min_confidence');

        $grounded = [];
        foreach ($ids->chunk(1000) as $chunk) {
            ClassificationResult::whereIn('classification_item_id', $chunk)
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
        }

        return $grounded;
    }
}
