<section class="p-5 sm:p-8 max-w-[1080px]">
  <div class="flex items-end justify-between flex-wrap gap-3 mb-6">
    <div>
      <p class="kicker mb-1.5">{{ __('XİF MN · goods & services') }}</p>
      <h1 class="font-display text-4xl">{{ __('Classify') }}</h1>
    </div>
  </div>

  <div class="card p-6">
    <label class="field-label">{{ __('Items — one per line (max :n)', ['n' => number_format($manualLimit)]) }}</label>
    <textarea wire:model="input" rows="4" placeholder="{{ __('e.g. Şpris 5ml 23G rezin porşenli') }}"
              class="field-input font-mono text-sm" style="height:auto"></textarea>
    <div class="flex flex-wrap items-center gap-2 mt-3">
      @foreach($examples as $ex)
        <button type="button" wire:click="useExample(@js($ex))" class="btn btn-ghost btn-sm">+ {{ Str::limit($ex, 32) }}</button>
      @endforeach
      <button wire:click="run" wire:loading.attr="disabled" wire:target="run" class="btn btn-ink btn-sm ml-auto">
        <span wire:loading.remove wire:target="run">{{ __('Match items →') }}</span>
        <span wire:loading wire:target="run">{{ __('Queuing…') }}</span>
      </button>
    </div>
  </div>

  {{-- Files have one door — the Upload page (preview, big files, lines the chat reads). --}}
  <div class="card-flat p-5 mt-4 flex items-center justify-between flex-wrap gap-3">
    <div>
      <p class="font-medium">{{ __('Have a file?') }}</p>
      <p class="text-muted text-sm">{{ __('Upload it on the Upload page — a list of item names or an invoice export: a preview first, big files in the background, and the chat sees its lines.') }}</p>
    </div>
    <a href="{{ route('upload') }}" class="btn btn-ghost btn-sm">{{ __('Go to Upload →') }}</a>
  </div>

  {{-- Live progress for the active upload --}}
  @if($progress)
    @include('livewire.partials.batch-progress', ['moreLabel' => __('Classify more'), 'from' => 'classify'])
  @endif
</section>
