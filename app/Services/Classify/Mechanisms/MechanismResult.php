<?php

namespace App\Services\Classify\Mechanisms;

// One mechanism's decision for one item. Maps 1:1 onto a classification_results
// row (minus classification_item_id + mechanism, which the orchestrator sets).
final class MechanismResult
{
    /**
     * @param  array<int, mixed>  $candidates  retrieval candidates the mechanism chose from
     * @param  array<int, mixed>  $path  descent trail (broker) — empty for flat mechanisms
     * @param  array<string, int>  $usage  token usage for this result
     */
    public function __construct(
        public readonly ?string $matchedCode,
        public readonly ?int $catalogId,
        public readonly ?string $kind,
        public readonly ?float $confidence,
        public readonly string $status,
        public readonly array $candidates = [],
        public readonly array $path = [],
        public readonly ?string $explanation = null,
        public readonly ?string $model = null,
        public readonly ?int $tier = null,
        public readonly array $usage = [],
    ) {}

    /** @return array<string, mixed> Column values for a classification_results row. */
    public function toRow(): array
    {
        return [
            'matched_code' => $this->matchedCode,
            'catalog_id' => $this->catalogId,
            'kind' => $this->kind,
            'confidence' => $this->confidence,
            'status' => $this->status,
            'candidates' => $this->candidates,
            'path' => $this->path !== [] ? $this->path : null,
            'explanation' => $this->explanation,
            'model' => $this->model,
            'tier' => $this->tier,
            'usage' => $this->usage !== [] ? $this->usage : null,
        ];
    }
}
