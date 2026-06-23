<?php

namespace App\Services\Llm;

use RuntimeException;

class JsonExtractor
{
    /**
     * Decode a JSON object from an LLM response, tolerating ```json fences and
     * surrounding prose.
     *
     * @return array<string, mixed>
     */
    public static function decode(string $raw): array
    {
        $text = trim($raw);

        // Strip ```json ... ``` or ``` ... ``` fences.
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', (string) $text);
            $text = trim((string) $text);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fallback: grab the outermost {...} span.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $slice = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($slice, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException('Could not parse JSON from model output: '.mb_substr($raw, 0, 300));
    }
}
