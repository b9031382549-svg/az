<?php

namespace App\Livewire;

use App\Models\ActivityLog;
use App\Models\AnswerCache;
use App\Models\ClassificationItem;
use App\Models\ImportBatch;
use App\Models\RubricatorNode;
use App\Support\Audit;
use Livewire\Attributes\Layout;
use Livewire\Component;

// One memory entry (answer_cache row): its code, category, how it got into memory, how
// often it's been answered from memory, and the edit/delete controls. Provenance maps to
// the four write paths (base import / unanimous consensus / grounded web search / human
// confirm) via source + tier.
#[Layout('components.app-layout', ['title' => 'Memory entry'])]
class MemoryItem extends Component
{
    public AnswerCache $cache;

    public string $newCode = '';

    public function mount(AnswerCache $cache): void
    {
        $this->cache = $cache;
        $this->newCode = (string) ($cache->is_service ? '99' : $cache->heading);
    }

    /** Change the heading this name resolves to (logged, visible in the history). */
    public function changeCode(): void
    {
        $code = trim($this->newCode);
        if (! preg_match('/^\d{4}$/', $code) && $code !== '99') {
            $this->addError('newCode', __('Enter a 4-digit heading or 99 for a service.'));

            return;
        }

        $was = $this->cache->is_service ? '99' : (string) $this->cache->heading;
        if ($code === $was) {
            return;
        }

        $this->cache->update([
            'heading' => $code === '99' ? null : $code,
            'is_service' => $code === '99',
        ]);
        Audit::log('memory.code.changed', ['from' => $was, 'to' => $code], $this->cache);
        $this->cache->refresh();
    }

    /** Remove the entry from memory (logged). */
    public function deleteEntry()
    {
        Audit::log('memory.entry.removed', ['name' => $this->cache->name, 'heading' => $this->cache->heading], $this->cache);
        $this->cache->delete();

        return $this->redirect(route('catalog'), navigate: true);
    }

    public function render()
    {
        $heading = $this->cache->is_service ? '99' : (string) $this->cache->heading;

        // Category name: the 4-digit position title, plus its chapter.
        $position = $heading !== '99'
            ? RubricatorNode::where('code', $heading)->first()
            : null;
        $chapter = $heading !== '99'
            ? RubricatorNode::where('level', 1)->where('code', mb_substr($heading, 0, 2))->first()
            : null;

        // How this name got into memory — one of the four write paths.
        $provenance = $this->provenance();

        // The originating run, if the promotion tagged an item_id.
        $itemId = data_get($this->cache->meta, 'item_id');
        $batchLabel = null;
        if ($itemId) {
            $batch = ClassificationItem::whereKey($itemId)->value('batch');
            $batchLabel = $batch ? (ImportBatch::where('key', $batch)->value('label') ?? $batch) : null;
        }

        $history = ActivityLog::where('subject_type', $this->cache->getMorphClass())
            ->where('subject_id', $this->cache->getKey())
            ->orderByDesc('id')->limit(50)->get();

        return view('livewire.memory-item', [
            'heading' => $heading,
            'positionTitle' => $position?->localizedTitle(),
            'chapterTitle' => $chapter?->localizedTitle(),
            'provenance' => $provenance,
            'batchLabel' => $batchLabel,
            'history' => $history,
        ]);
    }

    /** @return array{label:string, note:string} */
    private function provenance(): array
    {
        $tier = (string) ($this->cache->tier ?? '');
        $source = (string) ($this->cache->source ?? '');

        return match (true) {
            $tier === 'human', $source === 'confirmed' => ['label' => __('Human confirmed'), 'note' => __('A reviewer signed off on this heading.')],
            $tier === 'grounded', str_contains($source, 'grounded') => ['label' => __('Web-search resolved'), 'note' => __('The web-search resolver settled it above the confidence threshold.')],
            $tier === 'auto', str_contains($source, 'consensus') => ['label' => __('Unanimous consensus'), 'note' => __('Broker and Direct agreed and the vector corroborated — auto-promoted.')],
            default => ['label' => __('Base import'), 'note' => __('Loaded from the reference dataset (:source).', ['source' => $source ?: '—'])],
        };
    }
}
