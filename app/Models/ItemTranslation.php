<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One cached translation of an uploaded item name into English / Russian. Keyed
 * by a normalized hash of the original (Azerbaijani) text so identical items
 * collapse to a single row and are translated once.
 */
class ItemTranslation extends Model
{
    protected $guarded = ['id'];

    /**
     * Stable key for an item name: trimmed, whitespace-collapsed, lower-cased.
     * Same concept -> same hash -> one translation, regardless of casing/spacing.
     *
     * Azerbaijani-aware case folding: plain mb_strtolower turns the dotted capital
     * 'İ' (U+0130) into 'i' + U+0307 (a combining dot) and the dotless 'I' into a
     * dotted 'i', so an item typed in upper- vs lower-case would otherwise produce
     * two different hashes. We map the I/İ pair to their Azerbaijani lowercase
     * forms first, then lower-case the rest, then drop any stray combining dot.
     */
    public static function hashFor(string $text): string
    {
        $norm = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $norm = str_replace(['İ', 'I'], ['i', 'ı'], $norm);
        $norm = mb_strtolower($norm, 'UTF-8');
        $norm = str_replace("\u{0307}", '', $norm); // stray combining dot above

        return hash('sha256', $norm);
    }

    /**
     * The translation for a locale, or null when missing. `az` is the base and
     * never has a stored translation (callers fall back to the original text).
     */
    public function forLocale(string $locale): ?string
    {
        $value = match ($locale) {
            'en' => $this->en,
            'ru' => $this->ru,
            default => null,
        };

        return ($value !== null && $value !== '') ? $value : null;
    }
}
