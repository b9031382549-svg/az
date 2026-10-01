<?php

namespace App\Services\Classify;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * HTTP client of the sorter container (docker/sorter): a line → the probabilities that it
 * names a good, a service, or no product at all (trash). Fail-open by design: any error
 * returns null and the pipeline runs exactly as it would without the sorter.
 */
final class SorterClient
{
    /** Names per request (the service itself runs the model 64 at a time). */
    private const CHUNK = 256;

    public function enabled(): bool
    {
        return trim((string) config('classify.sorter.url', '')) !== '';
    }

    /**
     * @param  array<array-key, string>  $texts
     * @param  int|null  $timeout  seconds to wait (default classify.sorter.timeout)
     * @return array<array-key, array<string, float>>|null keyed like $texts, each label =>
     *                                                     probability; null when unavailable
     */
    public function classify(array $texts, ?int $timeout = null): ?array
    {
        if ($texts === []) {
            return [];
        }
        if (! $this->enabled()) {
            return null;
        }

        $url = rtrim((string) config('classify.sorter.url'), '/').'/classify';
        $out = [];
        try {
            foreach (array_chunk($texts, self::CHUNK, true) as $chunk) {
                $body = Http::timeout(max(1, $timeout ?? (int) config('classify.sorter.timeout', 120)))
                    ->acceptJson()
                    ->post($url, ['texts' => array_values($chunk)])
                    ->throw()
                    ->json();
                $labels = array_values((array) ($body['labels'] ?? []));
                $probs = array_values((array) ($body['probs'] ?? []));
                if ($labels === [] || count($probs) !== count($chunk)) {
                    throw new RuntimeException('Unexpected sorter payload.');
                }
                foreach (array_keys($chunk) as $i => $key) {
                    $row = array_map('floatval', array_values((array) $probs[$i]));
                    if (count($row) !== count($labels)) {
                        throw new RuntimeException('Unexpected sorter payload.');
                    }
                    $out[$key] = array_combine($labels, $row);
                }
            }
        } catch (Throwable $e) {
            Log::warning('sorter.unavailable', ['error' => $e->getMessage()]);

            return null;
        }

        return $out;
    }
}
