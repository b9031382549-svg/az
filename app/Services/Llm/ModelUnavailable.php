<?php

namespace App\Services\Llm;

use RuntimeException;

/**
 * The model server could not answer at all: unreachable, timed out, overloaded (429), the
 * model not loaded (404), or a server error (5xx). Not a bad answer — the same request may
 * well succeed once the server is back. A RuntimeException, so every existing catch keeps
 * its behaviour; only the on-premise wait (config classify.wait_for_models) treats it apart.
 */
class ModelUnavailable extends RuntimeException
{
    /** Statuses that say "the server is not able to answer now", as opposed to "bad request". */
    public static function isUnavailableStatus(int $status): bool
    {
        return $status === 404 || $status === 429 || $status >= 500;
    }
}
