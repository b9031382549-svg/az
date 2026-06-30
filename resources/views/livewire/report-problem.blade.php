<footer class="shrink-0 px-5 sm:px-7 py-2 border-t hair flex items-center justify-end"
        x-data="{ open: false }"
        x-on:keydown.escape.window="open = false">
  @once
    <style>[x-cloak]{display:none !important}</style>
  @endonce

  <button type="button"
          x-on:click="open = true; $wire.call('resetForm')"
          class="text-faint text-xs hover:text-stamp transition flex items-center gap-1.5">
    <span aria-hidden="true">⚠</span> {{ __('Report a problem') }}
  </button>

  {{-- Modal --}}
  <div x-cloak x-show="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/40 backdrop-blur-sm" x-on:click="open = false"></div>

    <div class="relative card bg-paper w-full max-w-md p-6 shadow-xl"
         x-transition.opacity x-on:click.stop
         x-effect="open && $nextTick(() => $refs.msg && $refs.msg.focus())">
      @if($sent)
        <div class="text-center py-3">
          <div class="mx-auto w-11 h-11 grid place-items-center rounded-2xl bg-ledger/12 text-ledger text-xl mb-3">✓</div>
          <h3 class="font-display text-xl mb-1">{{ __('Thank you!') }}</h3>
          <p class="text-muted text-sm mb-5">{{ __('Your report was sent. We can trace the details by this request id.') }}</p>
          <button type="button" x-on:click="open = false" class="btn btn-ink btn-sm">{{ __('Close') }}</button>
        </div>
      @else
        <div class="flex items-start justify-between gap-3 mb-3">
          <h3 class="font-display text-xl">{{ __('Report a problem') }}</h3>
          <button type="button" x-on:click="open = false" class="text-faint hover:text-ink text-lg leading-none">✕</button>
        </div>

        <p class="text-muted text-sm mb-3">{{ __('Describe what went wrong. The request id is attached automatically — we\'ll use it to find the details in the logs.') }}</p>

        <div class="card-flat px-3 py-2 mb-3 text-xs flex items-center gap-2">
          <span class="text-faint">{{ __('Request') }}</span>
          <code class="font-mono text-ink break-all">{{ $requestId ?: '—' }}</code>
        </div>

        <textarea wire:model="message" rows="4" x-ref="msg"
                  placeholder="{{ __('e.g. clicking “Match items” does nothing…') }}"
                  class="field-input text-sm" style="height:auto"></textarea>
        @error('message') <p class="text-stamp text-xs mt-1.5">{{ $message }}</p> @enderror

        <div class="flex justify-end gap-2 mt-4">
          <button type="button" x-on:click="open = false" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</button>
          <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit" class="btn btn-ink btn-sm">
            <span wire:loading.remove wire:target="submit">{{ __('Send') }}</span>
            <span wire:loading wire:target="submit">{{ __('Sending…') }}</span>
          </button>
        </div>
      @endif
    </div>
  </div>
</footer>
