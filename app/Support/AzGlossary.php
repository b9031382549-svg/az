<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * De-noise + ground the head-noun of a noisy invoice line against the catalog — the
 * project's own large Azerbaijani product dictionary. For each distinctive token it
 * finds the CLEAN spelled catalog term(s) that word actually names and the kind of
 * goods (chapter title), so the brief stops misreading garbled AZ words ("BALGABAG"
 * → balqabaq/pumpkin, not "balloon bag"). Catalog-scoped (only real tradeable
 * vocabulary) and word-boundary matched (so "bal" ⊄ "balıq"). NOT the vector's
 * dynamic retrieval — a deterministic vocabulary lookup, like the brief's existing
 * hand glossary but data-driven and catalog-wide.
 */
final class AzGlossary
{
    // Top heading must hold ≥60% of a token's occurrences to count as its dominant
    // sense — filters common words (orta, hava; spread evenly) and picks the dominant
    // meaning of a polysemous one instead of a random rare sense.
    private const MIN_SHARE = 0.6;

    /**
     * Glossary hints for the item's distinctive tokens.
     *
     * @return array<int, array{token: string, example: string, area: string}>
     */
    public function hintsFor(string $text, int $max = 2): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        $folded = AzFold::fold(mb_strtolower($text));
        $tokens = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}]+/u', $folded) ?: [],
            fn ($w) => mb_strlen($w) >= 3,
        )));
        if ($tokens === []) {
            return [];
        }

        // A token is a real head-noun ONLY if it occurs as a WHOLE WORD (\y…\y) with a
        // dominant sense in the catalog — no brand stoplist needed, junk simply doesn't
        // occur. On a MISS, try phonetic variants (q↔g↔k↔ğ, the common AZ invoice
        // consonant swap) so a misspelled BALGABAG still finds balqabaq.
        $cand = [];
        foreach ($tokens as $t) {
            $hit = $this->dominantHeading($t);
            if ($hit === null) {
                foreach ($this->phoneticVariants($t) as $v) {
                    $hit = $this->dominantHeading($v);
                    if ($hit !== null) {
                        break;
                    }
                }
            }
            if ($hit !== null) {
                $cand[$t] = $hit;
            }
        }
        // Most dominant sense first (clearest word).
        uasort($cand, fn ($a, $b) => $b['share'] <=> $a['share']);

        $hints = [];
        foreach ($cand as $t => $c) {
            $row = $this->representative($c['term'], $c['top']);
            if ($row === null) {
                continue;
            }
            $hints[] = [
                'token' => $t, // the original (garbled) token, so the brief maps it to the clean example
                'example' => BreadcrumbName::fit((string) $row->name, 70),
                'area' => HsChapters::AZ[substr((string) $row->code, 0, 2)] ?? '',
            ];
            if (count($hints) >= $max) {
                break;
            }
        }

        return $hints;
    }

    /**
     * The dominant heading for a term, or null when it never occurs as a whole word or
     * has no dominant sense (common/ambiguous word).
     *
     * @return array{share: float, top: string, term: string}|null
     */
    private function dominantHeading(string $term): ?array
    {
        $byHeading = DB::table('catalog')->where('is_active', true)
            ->whereRaw('search_text ~* ?', ['\y'.$term.'\y'])
            ->selectRaw('substr(code, 1, 4) AS heading, count(*) AS c')
            ->groupBy('heading')->orderByDesc('c')->get();
        if ($byHeading->isEmpty()) {
            return null;
        }
        $total = $byHeading->sum('c');
        $top = $byHeading->first();
        if ($total < 1 || $top->c / $total < self::MIN_SHARE) {
            return null;
        }

        return ['share' => $top->c / $total, 'top' => (string) $top->heading, 'term' => $term];
    }

    /** A representative catalog row for a term within its dominant heading: a GOOD over a service, shortest name. */
    private function representative(string $term, string $heading): ?object
    {
        return DB::table('catalog')->where('is_active', true)
            ->whereRaw('search_text ~* ?', ['\y'.$term.'\y'])
            ->whereRaw('substr(code, 1, 4) = ?', [$heading])
            ->orderByRaw("(kind = 'good') DESC, length(name) ASC")
            ->first(['name', 'code']);
    }

    /**
     * Phonetic variants swapping the interchangeable AZ consonant class {q,g,k,ğ} at
     * each such position (BALGABAG↔balqabaq). Bounded: skip words with >2 soft
     * consonants and cap the output, so long words don't explode into many queries.
     *
     * @return array<int, string>
     */
    private function phoneticVariants(string $token): array
    {
        $class = ['q', 'g', 'k', 'ğ'];
        $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $positions = [];
        foreach ($chars as $i => $ch) {
            if (in_array($ch, $class, true)) {
                $positions[] = $i;
            }
        }
        if ($positions === [] || count($positions) > 2) {
            return [];
        }

        $arrays = [$chars];
        foreach ($positions as $pos) {
            $next = [];
            foreach ($arrays as $variant) {
                foreach ($class as $c) {
                    $variant[$pos] = $c;
                    $next[] = $variant;
                }
            }
            $arrays = $next;
        }

        $out = [];
        foreach ($arrays as $arr) {
            $w = implode('', $arr);
            if ($w !== $token) {
                $out[$w] = true;
            }
        }

        return array_slice(array_keys($out), 0, 16);
    }
}
