<?php

namespace App\Livewire;

use App\Models\TestDataset;
use App\Models\TestRun;
use App\Services\Testing\DatasetMemory;
use App\Services\Testing\TestRunner;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

// One dataset: its labelled rows and the history of scored runs; launch a new run.
#[Layout('components.app-layout', ['title' => 'Dataset'])]
class TestingDataset extends Component
{
    use WithPagination;

    public TestDataset $dataset;

    public string $description = '';

    // Run mechanism overrides — seeded from the dataset default, tweakable per run.
    public bool $useVector = true;

    public bool $useBroker = true;

    public bool $useDirect = true;

    public bool $useMemory = false;

    public bool $useSearch = true;

    /** Selected done-run to seed this dataset's memory from (flywheel replay). */
    public string $seedRunId = '';

    public function mount(TestDataset $dataset): void
    {
        $this->dataset = $dataset;
        $m = $dataset->mechanisms ?? [];
        $enabled = (array) ($m['enabled'] ?? ['vector', 'broker', 'direct']);
        $this->useVector = in_array('vector', $enabled, true);
        $this->useBroker = in_array('broker', $enabled, true);
        $this->useDirect = in_array('direct', $enabled, true);
        $this->useMemory = (bool) ($m['cache'] ?? false);
        $this->useSearch = (bool) ($m['search'] ?? true);
    }

    public function launch(TestRunner $runner): void
    {
        $this->validate(['description' => 'required|string|max:200']);

        try {
            $run = $runner->launch($this->dataset, $this->description, $this->mechanisms());
        } catch (RuntimeException $e) {
            $this->addError('description', $e->getMessage());

            return;
        }

        $this->redirectRoute('testing.run', ['run' => $run->id], navigate: true);
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

    /** Seed the dataset's memory from its own correct answers (perfect-memory ceiling). */
    public function seedMemoryFromLabels(DatasetMemory $memory): void
    {
        $memory->seedFromLabels($this->dataset);
    }

    /** Seed the dataset's memory from a completed run's produced answers (flywheel replay). */
    public function seedMemoryFromRun(DatasetMemory $memory): void
    {
        $run = $this->seedRunId !== '' ? TestRun::find((int) $this->seedRunId) : null;
        if ($run !== null && (int) $run->test_dataset_id === $this->dataset->id) {
            $memory->seedFromRun($run);
        }
        $this->seedRunId = '';
    }

    public function clearMemory(DatasetMemory $memory): void
    {
        $memory->clear($this->dataset);
    }

    public function render()
    {
        return view('livewire.testing-dataset', [
            'rows' => $this->dataset->rows()->orderBy('id')->paginate(20),
            'runs' => $this->dataset->runs()->latest()->get(),
            'doneRuns' => $this->dataset->runs()->where('status', 'done')->latest()->get(),
            'scorable' => $this->dataset->scorableRows()->count(),
            'memoryCount' => app(DatasetMemory::class)->count($this->dataset),
        ]);
    }
}
