<?php

namespace App\Support;

/**
 * Fits a (possibly very long) HS breadcrumb catalog name into a character budget
 * WITHOUT losing its tail. Catalog names are breadcrumbs where siblings share a
 * long common head ("...medical instruments...") and differ only in the tail
 * ("...syringes, plastic"). A plain head-truncation therefore hides exactly what
 * tells candidates apart — so on any text handed to the LLM we keep the head (for
 * category context) AND the tail (for specificity), dropping only the middle
 * breadcrumb levels when the name is too long.
 */
final class BreadcrumbName
{
    public static function fit(string $name, int $max = 900): string
    {
        $name = trim($name);
        if ($max <= 1 || mb_strlen($name) <= $max) {
            return $name;
        }

        // Bias to the tail: it carries the distinguishing leaf description.
        $head = (int) floor(($max - 1) * 0.4);
        $tail = $max - 1 - $head;

        return rtrim(mb_substr($name, 0, $head)).'…'.ltrim(mb_substr($name, -$tail));
    }
}
