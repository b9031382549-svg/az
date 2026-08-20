<?php

namespace App\Support;

/**
 * Leaf-level helpers for catalog names. A catalog `name` is a "–"-separated
 * hierarchical path; the LAST segment is the leaf. A leaf that is exactly a bare
 * catch-all word ("– others", "– parts") is a service bucket, not a real product —
 * a poor positive for embedder training. Compound leaves ("from other textile
 * materials") are real descriptors and are NOT service.
 */
final class CatalogLeaf
{
    /** @var array<int, string> bare service/other leaf words */
    public const MISC = [
        'digərləri', 'digəri', 'digər', 'sairləri', 'sair', 'başqaları', 'başqa',
        'hissələr', 'hissələri', 'hissəsi', 'hissə',
    ];

    /** The last "–"-segment, trimmed and lower-cased. */
    public static function leaf(string $name): string
    {
        $parts = preg_split('/–/u', $name) ?: [$name];

        return rtrim(mb_strtolower(trim((string) end($parts)), 'UTF-8'), ' :;');
    }

    /** True when the leaf is a bare service/other bucket. */
    public static function isMisc(string $name): bool
    {
        return in_array(self::leaf($name), self::MISC, true);
    }
}
