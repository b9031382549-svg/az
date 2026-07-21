<?php

namespace App\Livewire;

use App\Models\TestDataset;
use App\Services\Testing\DatasetImporter;
use App\Support\Audit;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

// Testing tab — measure classifier accuracy on saved datasets and compare runs.
#[Layout('components.app-layout', ['title' => 'Testing'])]
class Testing extends Component
{
    use WithFileUploads;

    public $file;

    public string $name = '';

    // Default mechanism set for a new dataset (memory off in Phase 1 — its accuracy is
    // circular without a train/test split; see the plan's Phase 2).
    public bool $useVector = true;

    public bool $useBroker = true;

    public bool $useDirect = true;

    public bool $useMemory = false;

    public bool $useSearch = true;

    public function createDataset(DatasetImporter $importer): void
    {
        $this->validate([
            'name' => 'required|string|max:120',
            'file' => 'required|file|max:25600',
        ]);

        $ext = strtolower((string) $this->file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $this->addError('file', __('Please upload a .xlsx, .xls or .csv file.'));

            return;
        }

        ini_set('memory_limit', '768M');
        $rows = $importer->rows($this->file->getRealPath());
        if ($rows === []) {
            $this->addError('file', __('No rows found in the file.'));

            return;
        }

        $dataset = TestDataset::create([
            'name' => trim($this->name),
            'mechanisms' => $this->mechanisms(),
            'user_id' => auth()->id(),
        ]);
        $dataset->rows()->createMany($rows);

        Audit::log('testing.dataset_created', [
            'dataset' => $dataset->id,
            'name' => $dataset->name,
            'rows' => count($rows),
        ]);

        $this->reset('file', 'name');
        $this->redirectRoute('testing.dataset', ['dataset' => $dataset->id], navigate: true);
    }

    /** @return array{enabled:array<int,string>, shadow:array<int,string>, cache:bool, search:bool} */
    private function mechanisms(): array
    {
        return [
            'enabled' => array_values(array_filter([
                $this->useVector ? 'vector' : null,
                $this->useBroker ? 'broker' : null,
                $this->useDirect ? 'direct' : null,
            ])),
            'shadow' => [],
            'cache' => $this->useMemory,
            'search' => $this->useSearch,
        ];
    }

    public function render()
    {
        return view('livewire.testing', [
            'datasets' => TestDataset::withCount(['rows', 'runs'])->latest()->get(),
        ]);
    }
}
