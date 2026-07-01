<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Classification extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'candidates' => 'array',
            'confidence' => 'float',
        ];
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(CatalogCode::class, 'catalog_id');
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
