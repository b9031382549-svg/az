<?php

namespace App\Services\Classify;

use App\Models\ClassificationItem;
use App\Models\GoldLabel;
use Illuminate\Support\Collection;

/**
 * Scores our own classifications against the external reference ("gold") labels.
 *
 * Matching is by GoldLabel::keyFor(name) — the SAME normalization applied to the
 * reference name and to our item's source_text (kept in ONE place so matches
 * never silently drift). Each reference is scored at the granularity it actually
 * provides: Ivan at the full 10-digit code (and, looser, the 4-digit heading);
 * Fedor at the 4-digit heading for goods and the good/service flag for services.
 *
 * A disagreement is a candidate for review, not proof we are wrong — the
 * reference is AI-labelled and can itself err.
 */
class BenchmarkService
{
    /**
     * @return array{
     *   sources: array<string, array<string, int>>,
     *   overlap: array<string, int>,
     *   rows: Collection<int, array<string, mixed>>
     * }
     */
    public function score(): array
    {
        // Score against the TRUSTWORTHY reference only: Ivan (full code) + Fedor's
        // validated tier (two models agreed). Fedor's single-model "claude" labels are
        // explicitly unreliable, so they'd only add noise to the accuracy — they still
        // exist in gold_labels and surface as a review hint, just not in the score.
        $gold = GoldLabel::query()
            ->where(fn ($q) => $q->where('source', '!=', 'fedor')->orWhere('tier', 'validated'))
            ->get();

        // Best classified item per name_key — prefer one that produced a final code,
        // newest first so the pick is deterministic when a name was classified more
        // than once.
        $itemsByKey = [];
        ClassificationItem::query()
            ->select('id', 'source_text', 'final_code', 'kind', 'resolution', 'batch')
            ->orderByDesc('id')
            ->get()
            ->each(function ($it) use (&$itemsByKey) {
                $k = GoldLabel::keyFor((string) $it->source_text);
                $cur = $itemsByKey[$k] ?? null;
                if ($cur === null || ($it->final_code && ! $cur->final_code)) {
                    $itemsByKey[$k] = $it;
                }
            });

        $sources = [];
        $rows = [];
        foreach ($gold as $g) {
            $cmp = $this->compare($g, $itemsByKey[$g->name_key] ?? null);
            $rows[] = $cmp;
            $sources[$g->source] ??= $this->emptyAgg();
            $this->accumulate($sources[$g->source], $cmp);
        }

        return [
            'sources' => $sources,
            'overlap' => $this->overlap($gold, $itemsByKey),
            'rows' => collect($rows),
        ];
    }

    /**
     * Compare one reference label to our best matching item.
     *
     * @return array<string, mixed>
     */
    private function compare(GoldLabel $g, ?ClassificationItem $item): array
    {
        $ourCode = $item?->final_code ?: null;
        $ourHeading = $ourCode ? mb_substr((string) $ourCode, 0, 4) : null;
        $ourIsService = $item && $item->kind !== null ? $item->kind === 'service' : null;

        $fullMatch = ($ourCode && $g->code) ? $ourCode === $g->code : null;
        $headingMatch = ($ourHeading && $g->heading) ? $ourHeading === $g->heading : null;
        $serviceMatch = ($ourIsService !== null && $g->is_service !== null) ? $ourIsService === $g->is_service : null;

        // Did we produce a usable answer for THIS reference's granularity?
        $produced = $item !== null && ($g->source === 'fedor' && $g->is_service ? $ourIsService !== null : $ourCode !== null);

        // Does the REFERENCE actually carry the field we'd compare at? A code-less
        // Ivan row (or a heading-less Fedor good) offers nothing to score against —
        // it is not a disagreement, just uncomparable.
        $comparable = match (true) {
            $g->source === 'ivan' => $g->code !== null,
            $g->is_service => true,                       // the service flag is always present
            default => $g->heading !== null,              // Fedor good
        };

        // The reference-appropriate "hit".
        $hit = match (true) {
            $g->source === 'ivan' => $fullMatch === true,
            $g->is_service => $serviceMatch === true,     // Fedor service
            default => $headingMatch === true,            // Fedor good
        };

        $status = match (true) {
            $item === null => 'unclassified',
            ! $produced => 'no_code',
            ! $comparable => 'no_ref',
            $hit => 'agree',
            default => 'disagree',
        };

        return [
            'source' => $g->source,
            'tier' => $g->tier,
            'name' => $g->name,
            'gold_code' => $g->code,
            'gold_heading' => $g->heading,
            'gold_service' => $g->is_service,
            'gold_category' => $g->category,
            'item_id' => $item?->id,
            'batch' => $item?->batch,
            'our_code' => $ourCode,
            'our_heading' => $ourHeading,
            'our_kind' => $item?->kind,
            'resolution' => $item?->resolution,
            'status' => $status,
            'full_match' => $fullMatch,
            'heading_match' => $headingMatch,
            'service_match' => $serviceMatch,
        ];
    }

    /** @return array<string, int> */
    private function emptyAgg(): array
    {
        return array_fill_keys([
            'total', 'matched', 'unclassified', 'no_code', 'no_ref', 'agree', 'disagree',
            'full_agree', 'full_total', 'heading_agree', 'heading_total', 'service_agree', 'service_total',
        ], 0);
    }

    /**
     * @param  array<string, int>  $agg
     * @param  array<string, mixed>  $cmp
     */
    private function accumulate(array &$agg, array $cmp): void
    {
        $agg['total']++;
        if ($cmp['item_id'] !== null) {
            $agg['matched']++;
        }
        $agg[$cmp['status']]++; // unclassified | no_code | no_ref | agree | disagree

        foreach (['full', 'heading', 'service'] as $g) {
            if ($cmp[$g.'_match'] !== null) {
                $agg[$g.'_total']++;
                if ($cmp[$g.'_match'] === true) {
                    $agg[$g.'_agree']++;
                }
            }
        }
    }

    /**
     * Triangulation across the two references: names present in BOTH, and how our
     * answer lands against them (agrees with both / one / neither), over the
     * subset we have actually classified.
     *
     * @param  Collection<int, GoldLabel>  $gold
     * @param  array<string, ClassificationItem>  $itemsByKey
     * @return array<string, int>
     */
    private function overlap(Collection $gold, array $itemsByKey): array
    {
        $bySource = $gold->groupBy('source')->map(fn ($g) => $g->keyBy('name_key'));
        $ivan = $bySource->get('ivan') ?? collect();
        $fedor = $bySource->get('fedor') ?? collect();
        $shared = array_intersect($ivan->keys()->all(), $fedor->keys()->all());

        $out = ['shared' => count($shared), 'classified' => 0, 'both' => 0, 'one' => 0, 'neither' => 0];
        foreach ($shared as $key) {
            $item = $itemsByKey[$key] ?? null;
            if ($item === null || ! $item->final_code) {
                continue;
            }
            $out['classified']++;
            $h = mb_substr((string) $item->final_code, 0, 4);
            $hitIvan = $h === $ivan[$key]->heading;      // Ivan at heading level (comparable to Fedor)
            $hitFedor = $h === $fedor[$key]->heading;
            $n = (int) $hitIvan + (int) $hitFedor;
            $out[$n === 2 ? 'both' : ($n === 1 ? 'one' : 'neither')]++;
        }

        return $out;
    }
}
