<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Normalizer;

/**
 * A reference label for a product name from an external AI-labelled file. Two
 * references co-exist (source = 'ivan' with a full 10-digit code, 'fedor' with a
 * 4-digit heading + good/service). We match our own classifications to these by
 * {@see self::keyFor()} and report agreement — see BenchmarkService.
 */
class GoldLabel extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_service' => 'boolean',
            'confidence' => 'float',
            'meta' => 'array',
        ];
    }

    /**
     * The single source of truth for name matching: the SAME transform must be
     * applied to the reference name (at import) and to our item's source_text (at
     * comparison), or matches silently fail. Deliberately conservative — NFKC +
     * lowercase + whitespace collapse only; it does not fold diacritics or scripts,
     * because both sides originate from the same invoice text.
     */
    public static function keyFor(string $name): string
    {
        $s = $name;
        if (class_exists(Normalizer::class)) {
            $s = Normalizer::normalize($s, Normalizer::FORM_KC) ?: $s;
        }
        $s = mb_strtolower(trim($s));
        $s = (string) preg_replace('/\s+/u', ' ', $s);

        // Cap to keep the key inside an indexable varchar (some names are 1000+ char
        // multi-item blobs). Both sides are capped identically, so matching is intact.
        return mb_substr($s, 0, 240);
    }
}
