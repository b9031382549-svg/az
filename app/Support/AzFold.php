<?php

namespace App\Support;

/**
 * Folds Azerbaijani special letters to their plain-Latin base and lowercases —
 * so a diacritic-stripped invoice term ("kisi koynek") matches the catalog's
 * correct spelling ("kişi köynəyi") in lexical search. Used ONLY for the lexical
 * leg (a folded search column + folded query); the embeddings keep the original
 * text, so the vector is unaffected.
 */
final class AzFold
{
    /** @var array<string, string> */
    private const MAP = [
        'ə' => 'e', 'Ə' => 'e',
        'ş' => 's', 'Ş' => 's',
        'ç' => 'c', 'Ç' => 'c',
        'ğ' => 'g', 'Ğ' => 'g',
        'ı' => 'i', 'İ' => 'i', 'I' => 'i',
        'ö' => 'o', 'Ö' => 'o',
        'ü' => 'u', 'Ü' => 'u',
    ];

    public static function fold(string $text): string
    {
        return mb_strtolower(strtr($text, self::MAP));
    }
}
