<?php

namespace App\Livewire;

use App\Livewire\Concerns\ConfirmsClassifications;
use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Models\GoldLabel;
use App\Models\RubricatorNode;
use Livewire\Attributes\Layout;
use Livewire\Component;

// "How was this decided" screen for one item, laid out as the STAGES of the current
// flow — cache → AI consensus (3 mechanisms) → web-search resolver → human — each
// showing its input → output up front, with the deep trace collapsible. The reviewer
// confirms / rejects / corrects the item here (moved from the review list).
#[Layout('components.app-layout', ['title' => 'Decision'])]
class ClassificationDecision extends Component
{
    use ConfirmsClassifications;

    public ClassificationItem $item;

    public function mount(ClassificationItem $item): void
    {
        $this->item = $item->load(['results', 'finalCode', 'translation', 'adjudications', 'confirmedBy']);
    }

    /** Confirm (or correct to) a code from the decision page. */
    public function confirmWith(string $code): void
    {
        $this->applyConfirm($this->item, $code);
        $this->item = $this->item->fresh(['results', 'finalCode', 'translation', 'adjudications', 'confirmedBy']);
    }

    public function reject(): void
    {
        $this->applyReject($this->item);
        $this->item = $this->item->fresh(['results', 'finalCode', 'translation', 'adjudications', 'confirmedBy']);
    }

    public function render()
    {
        // Every code referenced by a trace / candidates. Catalog (10-digit) leaves
        // get their localized name; rubricator (2/4/6-digit) forks get their
        // localized title. Both are re-resolved by code so old traces (which stored
        // Azerbaijani titles inline) translate too.
        $codes = collect();
        $rubricCodes = collect();
        foreach ($this->item->results as $r) {
            $codes = $codes
                ->merge(collect($r->candidates ?? [])->pluck('code'))
                ->push($r->matched_code);
            // Each mechanism's 4-digit heading, so the "what each proposed" line can label it.
            if ($r->matched_code) {
                $rubricCodes->push(mb_substr((string) $r->matched_code, 0, 4));
            }
            foreach (($r->trace['steps'] ?? []) as $step) {
                $rubricCodes->push($step['code'] ?? null);
                foreach (($step['options'] ?? []) as $opt) {
                    $codes->push($opt['code'] ?? null);
                    $rubricCodes->push($opt['code'] ?? null);
                }
            }
        }
        $codes = $codes->filter()->map(fn ($c) => (string) $c)->unique()->values();
        $rubricCodes = $rubricCodes->filter()->map(fn ($c) => (string) $c)->unique()->values();

        // A partial result (4-digit heading or the "99" service level) has no catalog
        // leaf — resolve its name from the rubricator instead. Cover the item's final
        // code AND the confirm-dropdown options (each allowed code's 4-digit heading).
        $rubricCodes = $rubricCodes
            ->merge(collect([$this->item->final_code])->merge($this->item->allowedCodes())
                ->filter()->map(fn ($c) => (string) mb_substr((string) $c, 0, 4)))
            ->unique()->values();

        $names = CatalogCode::whereIn('code', $codes)
            ->get(['code', 'name', 'name_en', 'name_ru'])
            ->mapWithKeys(fn ($c) => [(string) $c->code => $c->localizedName()]);

        $rubricTitles = RubricatorNode::whereIn('code', $rubricCodes)
            ->get(['code', 'title', 'title_en', 'title_ru'])
            ->mapWithKeys(fn ($n) => [(string) $n->code => $n->localizedTitle()]);

        // Reference ("gold") labels for this item — DISPLAY ONLY. A benchmark hint
        // for the reviewer; never part of how the item was (or will be) classified.
        $gold = GoldLabel::where('name_key', GoldLabel::keyFor((string) $this->item->source_text))->get();

        // Split the result rows into the stages of the flow.
        $results = $this->item->results;
        $order = ['vector' => 0, 'broker' => 1, 'direct' => 2];
        $mechResults = $results->whereIn('mechanism', ['vector', 'broker', 'direct'])
            ->sortBy(fn ($r) => $order[$r->mechanism] ?? 9)->values();
        $cache = $results->firstWhere('mechanism', 'cache');
        $search = $results->firstWhere('mechanism', 'search');
        $adj = $this->item->adjudications->sortByDesc('id')->first();

        // Recompute the 2-of-3 heading consensus for the AI-stage output line.
        $coded = $mechResults->filter(fn ($r) => $r->matched_code !== null && $r->matched_code !== '');
        $tally = $coded->groupBy(fn ($r) => mb_substr((string) $r->matched_code, 0, 4))->map->count();
        $topHeading = $tally->sortDesc()->keys()->first();
        $topCount = $topHeading !== null ? (int) $tally[$topHeading] : 0;
        $consensus = [
            'ran' => $mechResults->isNotEmpty(),
            'heading' => $topHeading,
            'agreed' => $topCount >= 2 && $topCount >= intdiv($mechResults->count(), 2) + 1,
            'top_count' => $topCount,
            'total' => $mechResults->count(),
        ];

        return view('livewire.classification-decision', [
            'names' => $names,
            'rubricTitles' => $rubricTitles,
            'gold' => $gold,
            'mechResults' => $mechResults,
            'cache' => $cache,
            'search' => $search,
            'adj' => $adj,
            'consensus' => $consensus,
        ]);
    }
}
