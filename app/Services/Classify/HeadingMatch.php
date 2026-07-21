<?php

namespace App\Services\Classify;

/**
 * The single source of truth for "did a prediction hit the expected answer",
 * scored at the 4-digit HS heading (goods) or the good/service flag (services).
 * Shared by the CLI accuracy harness (classify:accuracy-test) and the UI dataset
 * scorer so "what counts as correct" can never drift between them.
 */
class HeadingMatch
{
    /** The 4-digit heading of a code (or null for an empty/absent code). */
    public static function heading(?string $code): ?string
    {
        return ($code === null || $code === '') ? null : mb_substr($code, 0, 4);
    }

    /** A service if the kind says so or the code sits in chapter/heading 99. */
    public static function isService(?string $kind, ?string $code): bool
    {
        return $kind === 'service'
            || $kind === '99'
            || ($code !== null && str_starts_with($code, '99'));
    }

    /**
     * Is a mechanism's (code, kind) correct against the expected answer?
     * Services score on the flag; goods on the 4-digit heading.
     */
    public static function correct(?string $predCode, ?string $predKind, ?string $expectedHeading, bool $expectedService): bool
    {
        $predService = self::isService($predKind, $predCode);

        if ($expectedService) {
            return $predService === true;
        }

        $predHeading = self::heading($predCode);

        return $predService === false
            && $predHeading !== null
            && $predHeading === $expectedHeading;
    }
}
