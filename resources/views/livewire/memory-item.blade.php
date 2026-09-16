<section class="p-5 sm:p-8 max-w-[820px]">
  <div class="mb-6">
    <a href="{{ route('catalog') }}" wire:navigate class="text-sm text-muted hover:text-ink">← {{ __('Back to Catalog (memory)') }}</a>
    <h1 class="font-display text-3xl mt-3 break-words">{{ $cache->name }}</h1>
    <div class="flex items-center gap-2 mt-2 flex-wrap text-sm">
      <span class="font-mono px-2 py-0.5 rounded-md bg-line/40">{{ $heading }}</span>
      <span class="text-muted">{{ $positionTitle ?: ($heading === '99' ? __('Service') : '—') }}</span>
      @if($chapterTitle)<span class="text-faint">· {{ $chapterTitle }}</span>@endif
    </div>
  </div>

  <div class="grid sm:grid-cols-2 gap-4 mb-5">
    {{-- How it got here --}}
    <div class="card p-5">
      <p class="kicker mb-2">{{ __('How it entered memory') }}</p>
      <p class="font-medium">{{ $provenance['label'] }}</p>
      <p class="text-muted text-sm mt-1">{{ $provenance['note'] }}</p>
      @if($batchLabel)
        <p class="text-faint text-xs mt-3">{{ __('From upload') }}: {{ \Illuminate\Support\Str::limit($batchLabel, 40) }}</p>
      @endif
    </div>

    {{-- Usage --}}
    <div class="card p-5">
      <p class="kicker mb-2">{{ __('Answered from memory') }}</p>
      <p class="font-display text-3xl tnum">{{ number_format((int) $cache->hits) }} <span class="text-base text-muted font-sans">{{ __('times') }}</span></p>
      <p class="text-faint text-xs mt-2">
        {{ $cache->last_hit_at ? __('Last :when', ['when' => \Illuminate\Support\Carbon::parse($cache->last_hit_at)->diffForHumans()]) : __('Not answered from memory yet.') }}
      </p>
    </div>
  </div>

  {{-- Edit --}}
  <div class="card p-5 mb-5">
    <p class="kicker mb-3">{{ __('Change the code') }}</p>
    <div class="flex flex-wrap items-end gap-3">
      <div>
        <label class="field-label">{{ __('Heading (4-digit, or 99 for a service)') }}</label>
        <input wire:model="newCode" class="field-input font-mono w-40 h-10" maxlength="4">
        @error('newCode') <p class="text-sm text-stamp mt-1">{{ $message }}</p> @enderror
      </div>
      <button wire:click="changeCode" class="btn btn-ink btn-sm">{{ __('Save code') }}</button>
      <button wire:click="deleteEntry" wire:confirm="{{ __('Remove this entry from memory? It will no longer answer from the cache.') }}"
              class="btn btn-ghost btn-sm text-stamp ml-auto">{{ __('Remove from memory') }}</button>
    </div>
  </div>

  {{-- History --}}
  <div class="card p-5">
    <p class="kicker mb-3">{{ __('History') }}</p>
    @forelse($history as $h)
      <div wire:key="h-{{ $h->id }}" class="flex items-start gap-3 py-2 border-b hair last:border-0 text-sm">
        <span class="font-mono text-xs text-faint whitespace-nowrap mt-0.5">{{ \Illuminate\Support\Carbon::parse($h->created_at)->format('Y-m-d H:i') }}</span>
        <div class="min-w-0">
          <span class="font-medium">{{ str_replace(['memory.', '.'], ['', ' '], (string) $h->action) }}</span>
          @if($h->properties)
            <span class="text-muted">— {{ \Illuminate\Support\Str::limit(collect($h->properties)->map(fn ($v, $k) => "$k: $v")->implode(', '), 80) }}</span>
          @endif
        </div>
      </div>
    @empty
      <p class="text-muted text-sm">{{ __('No changes recorded yet.') }}</p>
    @endforelse
  </div>
</section>
