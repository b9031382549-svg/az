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

    /** Confidence of the mechanism result backing the final code, if any. */
    public function finalConfidence(): ?float
    {
        return $this->results->firstWhere('matched_code', $this->final_code)?->confidence;
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
