<?php

namespace App\Services\Classify;

use App\Models\ClassificationItem;
use Illuminate\Support\Collection;

/**
 * Measures each mechanism against the growing gold set of human-confirmed items
 * (resolution=confirmed → final_code is ground truth). Reads the stored
 * per-mechanism results, so it costs nothing to run and improves as reviewers
 * confirm more. This is how the broker is calibrated in shadow mode before it
 * becomes authoritative.
 */
class BrokerEvaluator
{
    /**
     * @param  array<int, string>  $mechanisms
     * @return array<string, mixed>
     */
    public function evaluate(array $mechanisms): array
    {
        $all = ClassificationItem::with('results')->get();
        $gold = $all->filter(fn ($i) => $i->resolution === 'confirmed' && $i->final_code !== null)->values();

        $result = [
            'total' => $all->count(),
            'sampleSize' => $gold->count(),
            'resolutions' => $all->groupBy('resolution')->map->count()->sortKeys()->toArray(),
            'mechanisms' => [],
            'agreement' => null,
        ];

        foreach ($mechanisms as $mech) {
            $result['mechanisms'][$mech] = $this->metrics($gold, $mech) + [
                'coverageAll' => $all->filter(fn ($i) => ($r = $i->results->firstWhere('mechanism', $mech)) && $r->matched_code !== null)->count(),
            ];
        }

        // Agreement is measured over ALL items (no ground truth needed) — the key
        // shadow-mode signal for how often the mechanisms already concur.
        if (count($mechanisms) >= 2) {
            $result['agreement'] = $this->agreement($all, $mechanisms[0], $mechanisms[1]);
        }

        return $result;
    }

    /**
     * @param  Collection<int, ClassificationItem>  $items
     * @return array<string, mixed>
     */
    private function metrics(Collection $items, string $mech): array
    {
        $buckets = [
            ['label' => '>=0.8', 'min' => 0.8, 'max' => 1.01, 'n' => 0, 'exact' => 0],
            ['label' => '0.6-0.8', 'min' => 0.6, 'max' => 0.8, 'n' => 0, 'exact' => 0],
            ['label' => '<0.6', 'min' => -1.0, 'max' => 0.6, 'n' => 0, 'exact' => 0],
        ];
        $n = $exact = $p6 = $p4 = $p2 = $tokens = 0;

        foreach ($items as $item) {
            $res = $item->results->firstWhere('mechanism', $mech);
            if (! $res || $res->matched_code === null) {
                continue;
            }
            $n++;
            $gold = (string) $item->final_code;
            $code = (string) $res->matched_code;

            $isExact = $this->prefix($code, $gold, 10);
            $exact += $isExact ? 1 : 0;
            $p6 += $this->prefix($code, $gold, 6) ? 1 : 0;
            $p4 += $this->prefix($code, $gold, 4) ? 1 : 0;
            $p2 += $this->prefix($code, $gold, 2) ? 1 : 0;
            $tokens += (int) ($res->usage['total_tokens'] ?? 0);

            $conf = (float) ($res->confidence ?? 0);
            foreach ($buckets as &$b) {
                if ($conf >= $b['min'] && $conf < $b['max']) {
                    $b['n']++;
                    $b['exact'] += $isExact ? 1 : 0;
                    break;
                }
            }
            unset($b);
        }

        return [
            'coverage' => $n,
            'exact' => $exact,
            'p6' => $p6,
            'p4' => $p4,
            'p2' => $p2,
            'avgTokens' => $n > 0 ? (int) round($tokens / $n) : 0,
            'buckets' => $buckets,
        ];
    }

    /**
     * @param  Collection<int, ClassificationItem>  $items
     * @return array<string, mixed>
     */
    private function agreement(Collection $items, string $a, string $b): array
    {
        $both = $match = 0;
        foreach ($items as $item) {
            $ra = $item->results->firstWhere('mechanism', $a);
            $rb = $item->results->firstWhere('mechanism', $b);
            if (! $ra || ! $rb || $ra->matched_code === null || $rb->matched_code === null) {
                continue;
            }
            $both++;
            $match += ((string) $ra->matched_code === (string) $rb->matched_code) ? 1 : 0;
        }

        return ['a' => $a, 'b' => $b, 'both' => $both, 'match' => $match];
    }

    private function prefix(string $code, string $gold, int $len): bool
    {
        return mb_substr($code, 0, $len) === mb_substr($gold, 0, $len);
    }
}
