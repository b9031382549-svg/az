<?php

namespace App\Livewire;

use App\Models\CatalogCode;
use App\Models\ClassificationItem;
use App\Models\RubricatorNode;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Read-only "how was this decided" screen for one item: per-mechanism flow
// (input -> essence -> candidates/forks -> pick -> gate) plus the consensus, so
// a reviewer can see exactly where a classification went right or wrong.
#[Layout('components.app-layout', ['title' => 'Decision'])]
class ClassificationDecision extends Component
{
    public ClassificationItem $item;

    public function mount(ClassificationItem $item): void
    {
        $this->item = $item->load(['results', 'finalCode', 'translation', 'adjudications']);
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

        $names = CatalogCode::whereIn('code', $codes)
            ->get(['code', 'name', 'name_en', 'name_ru'])
            ->mapWithKeys(fn ($c) => [(string) $c->code => $c->localizedName()]);

        $rubricTitles = RubricatorNode::whereIn('code', $rubricCodes)
            ->get(['code', 'title', 'title_en', 'title_ru'])
            ->mapWithKeys(fn ($n) => [(string) $n->code => $n->localizedTitle()]);

        return view('livewire.classification-decision', [
            'names' => $names,
            'rubricTitles' => $rubricTitles,
        ]);
    }
}
