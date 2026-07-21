<section class="p-5 sm:p-8 max-w-[1080px]">
  <div class="mb-5 flex items-end justify-between flex-wrap gap-3">
    <div>
      <p class="kicker mb-1.5">{{ __('Accuracy testing') }}</p>
      <h1 class="font-display text-4xl">{{ __('Testing') }}</h1>
    </div>
  </div>

  <p class="text-sm text-muted mb-5 max-w-[72ch]">
    {{ __('Measure the classifier on a labelled dataset (item name + correct code) and compare accuracy before and after a change. A run uses the exact production mechanisms and short-circuits the same way — every mechanism is scored at the 4-digit heading.') }}
  </p>

  {{-- New dataset --}}
  <div class="card p-6 mb-6">
    <p class="font-medium mb-3">{{ __('New dataset') }}</p>
    <div class="grid sm:grid-cols-2 gap-4">
      <div>
        <label class="field-label">{{ __('Name') }}</label>
        <input wire:model="name" class="field-input" placeholder="{{ __('e.g. Food invoices — July') }}">
        @error('name') <p class="text-sm text-stamp mt-1">{{ $message }}</p> @enderror
      </div>
      <div>
        <label class="field-label">{{ __('File — column A: item name, column B: correct code') }}</label>
        <input type="file" wire:model="file" accept=".xlsx,.xls,.csv" class="text-sm">
        @error('file') <p class="text-sm text-stamp mt-1">{{ $message }}</p> @enderror
      </div>
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-4">
      <span class="kicker">{{ __('Mechanisms') }}</span>
      @foreach([['useVector', __('Vector')], ['useBroker', __('Broker')], ['useDirect', __('Direct')], ['useSearch', __('Web search')], ['useMemory', __('Memory')]] as [$prop, $label])
        <label class="flex items-center gap-1.5 text-sm">
          <input type="checkbox" wire:model="{{ $prop }}"> {{ $label }}
        </label>
      @endforeach
      <button wire:click="createDataset" wire:loading.attr="disabled" wire:target="createDataset,file" class="btn btn-ink btn-sm ml-auto">
        <span wire:loading.remove wire:target="createDataset,file">{{ __('Create dataset →') }}</span>
        <span wire:loading wire:target="createDataset,file">{{ __('Importing…') }}</span>
      </button>
    </div>
  </div>

  {{-- Datasets --}}
  <div class="card p-0 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="text-muted text-left">
        <tr class="border-b hair">
          <th class="px-4 py-3 font-medium">{{ __('Dataset') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Rows') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Runs') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Created') }}</th>
        </tr>
      </thead>
      <tbody>
        @forelse($datasets as $d)
          <tr class="border-b hair hover:bg-surface">
            <td class="px-4 py-3"><a href="{{ route('testing.dataset', $d) }}" class="font-medium hover:underline">{{ $d->name }}</a></td>
            <td class="px-4 py-3 tnum">{{ $d->rows_count }}</td>
            <td class="px-4 py-3 tnum">{{ $d->runs_count }}</td>
            <td class="px-4 py-3 text-muted">{{ $d->created_at?->format('Y-m-d') }}</td>
          </tr>
        @empty
          <tr><td colspan="4" class="px-4 py-6 text-center text-muted">{{ __('No datasets yet.') }}</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
