<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// One uploaded line item and its resolved decision across all search
// mechanisms. Per-mechanism outputs live in classification_results; this row
// carries the consensus (resolution) and the final chosen code.
class ClassificationItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'search_resolved_at' => 'datetime',
        ];
    }

    /** @return HasMany<ClassificationResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(ClassificationResult::class);
    }

    /** @return HasMany<ClassificationAdjudication, $this> */
    public function adjudications(): HasMany
    {
        return $this->hasMany(ClassificationAdjudication::class);
    }

    public function finalCode(): BelongsTo
    {
        return $this->belongsTo(CatalogCode::class, 'final_catalog_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * The dataset test run that produced this item, if any. Prod items have a null
     * test_run_id; prod views exclude test rows with an explicit whereNull filter
     * (there is deliberately NO global scope — a static scope would silently break
     * the search-resolver's whereKey()->update() flip and route-model binding).
     */
    public function testRun(): BelongsTo
    {
        return $this->belongsTo(TestRun::class, 'test_run_id');
    }

    public function testDatasetRow(): BelongsTo
    {
        return $this->belongsTo(TestDatasetRow::class, 'test_dataset_row_id');
    }

    /**
     * Codes a reviewer may confirm for this item: every candidate any mechanism
     * considered, plus each mechanism's own pick (a mechanism's pick may not be
     * in another's candidate list). Requires `results` to be loaded.
     *
     * @return array<int, string>
     */
    public function allowedCodes(): array
    {
        return $this->results
            ->flatMap(fn ($r) => collect($r->candidates ?? [])->pluck('code')->push($r->matched_code))
            ->filter()
            ->map(fn ($c) => (string) $c)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Confidence backing the final code. An exact match wins (cache/search rows carry
     * the 4-digit heading verbatim); otherwise, since the answer is a 4-digit heading
     * that no vector/broker 10-digit row equals exactly, fall back to the strongest
     * mechanism whose code sits under that heading.
     */
    public function finalConfidence(): ?float
    {
        if ($this->final_code === null || $this->final_code === '') {
            return null;
        }

        $exact = $this->results->firstWhere('matched_code', $this->final_code)?->confidence;
        if ($exact !== null) {
            return $exact;
        }

        return $this->results
            ->filter(fn ($r) => $r->matched_code !== null && str_starts_with((string) $r->matched_code, (string) $this->final_code))
            ->max('confidence');
    }

    /** Cached display translation of this item's name, keyed by source_hash. */
    public function translation(): BelongsTo
    {
        return $this->belongsTo(ItemTranslation::class, 'source_hash', 'source_hash');
    }

    /**
     * The item name for the active UI locale, falling back to the original
     * Azerbaijani text when no translation exists. Display-only — never used for
     * retrieval/matching.
     */
    public function localizedSourceText(): string
    {
        $locale = app()->getLocale();
        if ($locale !== 'az') {
            $name = $this->translation?->forLocale($locale);
            if ($name !== null) {
                return $name;
            }
        }

        return (string) $this->source_text;
    }
}
