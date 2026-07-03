<?php

namespace App\Console\Commands;

use App\Models\CatalogCode;
use App\Models\HsCard;
use Illuminate\Console\Command;

/**
 * Enriches catalog.synonyms with the colloquial product terms from the HS cards'
 * INCLUDES — mapped PRECISELY by the include's 6-digit subheading note, never
 * broadcast across a whole heading (that would pollute sibling leaves, e.g. put
 * "ECG/MRI" onto a syringe leaf). Additive: existing (good, leaf-level) synonyms
 * are kept; card terms are merged in, deduped, and the result is length-bounded
 * so the embedding text is not diluted. Idempotent. Re-embed afterwards (3b) so
 * the vector leg also benefits; the lexical leg benefits immediately.
 */
class SyncCardSynonyms extends Command
{
    protected $signature = 'catalog:sync-card-synonyms {--dry-run : Report without writing} {--max-len=300 : Max chars of merged synonyms per code}';

    protected $description = 'Merge HS-card include synonyms into catalog.synonyms (precise 6-digit mapping)';

    public function handle(): int
    {
        $maxLen = max(60, (int) $this->option('max-len'));
        $dry = (bool) $this->option('dry-run');

        // Build: 6-digit subheading => unique term list, from card includes whose
        // note pins a subheading. Terms = product name + its synonyms, each split
        // on commas into atomic fragments — a card "product" is sometimes itself a
        // comma-list ("Rezin qrelka, klizma balonları, …"). Storing it whole then
        // re-splitting existing synonyms on commas would never round-trip, so the
        // phrase got re-added every run (harmless but non-idempotent). Splitting
        // here makes the merge converge to 0 new terms on a re-run.
        $bySub = [];
        foreach (HsCard::where('level', 2)->get(['includes']) as $card) {
            foreach ($card->includes ?? [] as $inc) {
                $terms = [];
                foreach (array_merge([$inc['product'] ?? ''], $inc['syn'] ?? []) as $raw) {
                    foreach (explode(',', (string) $raw) as $frag) {
                        $frag = trim($frag);
                        if ($frag !== '') {
                            $terms[] = $frag;
                        }
                    }
                }
                if ($terms === []) {
                    continue;
                }
                preg_match_all('/(\d{2})\.?(\d{2})\.?(\d{2})/', (string) ($inc['note'] ?? ''), $mm, PREG_SET_ORDER);
                foreach ($mm as $m) {
                    $sub = $m[1].$m[2].$m[3];
                    $bySub[$sub] = array_merge($bySub[$sub] ?? [], $terms);
                }
            }
        }
        foreach ($bySub as $sub => $terms) {
            $bySub[$sub] = $this->dedupe($terms);
        }
        $this->info(count($bySub).' subheadings carry card synonyms.');

        $touched = 0;
        $added = 0;
        CatalogCode::whereNotNull('subposition')
            ->select(['id', 'subposition', 'synonyms'])
            ->chunkById(1000, function ($rows) use ($bySub, $maxLen, $dry, &$touched, &$added) {
                foreach ($rows as $r) {
                    $cardTerms = $bySub[$r->subposition] ?? [];
                    if ($cardTerms === []) {
                        continue;
                    }
                    [$merged, $newCount] = $this->merge((string) ($r->synonyms ?? ''), $cardTerms, $maxLen);
                    if ($newCount === 0) {
                        continue;
                    }
                    $touched++;
                    $added += $newCount;
                    if (! $dry) {
                        CatalogCode::whereKey($r->id)->update(['synonyms' => $merged]);
                    }
                }
            });

        $this->info(($dry ? '[dry-run] ' : '').'Enriched '.$touched.' leaves with '.$added.' new terms.');

        return self::SUCCESS;
    }

    /**
     * Merge new terms into an existing comma-separated synonym string: keep the
     * existing terms first (they are precise, leaf-level), append new card terms
     * not already present (case-insensitive), and stop at the length budget.
     *
     * @param  array<int, string>  $newTerms
     * @return array{0: string, 1: int}
     */
    private function merge(string $existing, array $newTerms, int $maxLen): array
    {
        $kept = $this->dedupe(array_filter(array_map('trim', explode(',', $existing))));
        $seen = [];
        foreach ($kept as $t) {
            $seen[mb_strtolower($t)] = true;
        }

        $out = $kept;
        $len = mb_strlen(implode(', ', $out));
        $newCount = 0;
        foreach ($newTerms as $t) {
            $key = mb_strtolower($t);
            if (isset($seen[$key])) {
                continue;
            }
            if ($len + mb_strlen($t) + 2 > $maxLen) {
                break;
            }
            $seen[$key] = true;
            $out[] = $t;
            $len += mb_strlen($t) + 2;
            $newCount++;
        }

        return [implode(', ', $out), $newCount];
    }

    /**
     * @param  array<int, string>  $terms
     * @return array<int, string>
     */
    private function dedupe(array $terms): array
    {
        $seen = [];
        $out = [];
        foreach ($terms as $t) {
            $t = trim($t);
            $key = mb_strtolower($t);
            if ($t === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $t;
        }

        return $out;
    }
}
