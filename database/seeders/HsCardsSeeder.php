<?php

namespace Database\Seeders;

use App\Models\HsCard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Loads the HS legal cards from database/data/hs_cards.json — the version-
 * controlled corpus distilled (by Claude, from the HS notes / Explanatory Notes)
 * offline. Idempotent upsert by code, so re-running on deploy refreshes the set.
 */
class HsCardsSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/hs_cards.json');
        if (! File::exists($path)) {
            $this->command?->warn("hs_cards.json not found at {$path} — skipping.");

            return;
        }

        $cards = json_decode(File::get($path), true) ?: [];
        $codes = [];
        foreach ($cards as $c) {
            $codes[] = (string) $c['code'];
            HsCard::updateOrCreate(
                ['code' => (string) $c['code']],
                [
                    'level' => (int) ($c['level'] ?? 1),
                    'kind' => $c['kind'] ?? 'good',
                    'scope' => $c['scope'] ?? null,
                    'includes' => $c['includes'] ?? null,
                    'excludes' => $c['excludes'] ?? null,
                    'closed_list' => $c['closed_list'] ?? null,
                    'citations' => $c['citations'] ?? null,
                    'source' => $c['source'] ?? 'authored-from-notes',
                    'is_active' => true,
                ],
            );
        }

        // The JSON is the authoritative corpus: drop any card no longer present so
        // corrections/removals propagate on deploy. Guarded against an empty/failed
        // load (never wipe the table when the file yielded no codes).
        $pruned = $codes === [] ? 0 : HsCard::whereNotIn('code', $codes)->delete();

        $this->command?->info('Loaded '.count($cards).' HS cards'.($pruned ? ", pruned {$pruned} stale." : '.'));
    }
}
