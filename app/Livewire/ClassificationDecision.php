<?php

namespace App\Livewire;

use App\Models\CatalogCode;
use App\Models\ClassificationItem;
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
        $this->item = $item->load(['results', 'finalCode', 'translation']);
    }

    public function render()
    {
        // Localized catalog names for every code referenced by a trace / candidates.
        $codes = collect();
        foreach ($this->item->results as $r) {
            $codes = $codes
                ->merge(collect($r->candidates ?? [])->pluck('code'))
                ->push($r->matched_code);
            foreach (($r->trace['steps'] ?? []) as $step) {
                foreach (($step['options'] ?? []) as $opt) {
                    $codes->push($opt['code'] ?? null);
                }
            }
        }
        $codes = $codes->filter()->map(fn ($c) => (string) $c)->unique()->values();

        $names = CatalogCode::whereIn('code', $codes)
            ->get(['code', 'name', 'name_en', 'name_ru'])
            ->mapWithKeys(fn ($c) => [(string) $c->code => $c->localizedName()]);

        return view('livewire.classification-decision', [
            'names' => $names,
        ]);
    }
}
