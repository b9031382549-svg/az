<?php

namespace App\Support;

/**
 * What an API token may do (its Sanctum abilities) — the one list the Settings page offers
 * and the API routes check. A token without an ability gets 403 on that part of the API.
 */
final class ApiAbilities
{
    /** Send items to the classifier and read what it answered. */
    public const CLASSIFY = 'classify';

    /** Read classification results with their decision traces (/api/results, /api/uploads). */
    public const RESULTS = 'results';

    /** @return array<string, string> ability => what the Settings page calls it */
    public static function labels(): array
    {
        return [
            self::CLASSIFY => __('Classify items'),
            self::RESULTS => __('Read results and decision traces'),
        ];
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_keys(self::labels());
    }
}
