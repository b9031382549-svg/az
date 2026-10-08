<section class="flex flex-col h-[calc(100vh-4rem)]">

  <div class="flex-1 overflow-y-auto p-5 sm:p-8">
    @if(empty($messages))
      <div class="h-full grid place-items-center text-center">
        <div class="max-w-lg">
          <div class="mx-auto w-12 h-12 grid place-items-center rounded-2xl bg-ink text-paper font-display text-xl mb-4">✦</div>
          <h1 class="font-display text-3xl mb-2">{{ __('Ask about your invoices') }}</h1>
          <p class="text-muted mb-7">{{ __('Plain-language questions are translated into read-only SQL and run against your data.') }}</p>
          <div class="flex flex-wrap justify-center gap-2">
            @foreach($taxpayer ? $taxpayerSuggestions : $suggestions as $s)
              <button wire:click="suggest(@js(__($s)))"
                      class="btn btn-ghost btn-sm">{{ __($s) }}</button>
            @endforeach
          </div>
        </div>
      </div>
    @else
      <div class="max-w-[820px] mx-auto">
        <div class="flex justify-end mb-3">
          <button wire:click="clearHistory" wire:confirm="{{ __('Clear your entire chat history?') }}"
                  class="btn btn-ghost btn-sm text-muted">{{ __('Clear history') }}</button>
        </div>
        <div class="space-y-6">
        @foreach($messages as $m)
          <div class="flex flex-col items-end gap-1">
            <div class="bg-ink text-paper rounded-2xl rounded-br-sm px-4 py-2.5 max-w-[80%]">{{ $m['q'] }}</div>
            @if($m['tin'] !== '')
              <span class="text-faint text-xs font-mono">VÖEN {{ $m['tin'] }}</span>
            @endif
          </div>

          <div class="card p-5">
            @if($m['error'])
              <p class="text-stamp text-sm"><span class="font-medium">{{ __('Could not answer:') }}</span> {{ $m['error'] }}</p>
            @elseif(!empty($m['answer']) && empty($m['sql']))
              {{-- Conversational reply (no query was run) --}}
              <p>{{ $m['answer'] }}</p>
            @else
              @if($m['explanation'])
                <p class="mb-4">{{ $m['explanation'] }}</p>
              @endif

              @if(!empty($m['rows']))
                <div class="card-flat overflow-hidden">
                  <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                      <thead>
                        <tr class="text-left text-muted border-b hair bg-paper/50">
                          @foreach($m['columns'] as $col)
                            <th class="font-medium px-3.5 py-2.5 whitespace-nowrap">{{ $col }}</th>
                          @endforeach
                        </tr>
                      </thead>
                      <tbody>
                        @foreach($m['rows'] as $row)
                          <tr class="border-b hair last:border-0">
                            @foreach($m['columns'] as $col)
                              <td class="px-3.5 py-2.5 tnum whitespace-nowrap">
                                {{-- A VÖEN column (supplier_tin, recipient_tin, …): a click asks about that taxpayer. --}}
                                @if(($row[$col] ?? null) !== null && $row[$col] !== '' && preg_match('/(^|_)(tin|voen|vöen)$/iu', (string) $col))
                                  <button type="button" wire:click="setContext(@js((string) $row[$col]))"
                                          class="font-mono link-under hover:text-stamp" title="{{ __('Ask about this taxpayer') }}">{{ $row[$col] }}</button>
                                @else
                                  {{ $row[$col] }}
                                @endif
                              </td>
                            @endforeach
                          </tr>
                        @endforeach
                      </tbody>
                    </table>
                  </div>
                </div>
                @if($m['truncated'])
                  <p class="text-faint text-xs mt-2">{{ __('Showing the first 50 rows.') }}</p>
                @endif
              @else
                <p class="text-muted text-sm">{{ __('No rows returned.') }}</p>
              @endif

              @if($m['sql'])
                <details class="mt-4">
                  <summary class="kicker cursor-pointer select-none">SQL</summary>
                  <pre class="mt-2 text-xs font-mono bg-inkpanel text-paper rounded-xl p-3.5 overflow-x-auto whitespace-pre-wrap">{{ $m['sql'] }}</pre>
                </details>
              @endif
            @endif
          </div>
        @endforeach
        </div>
      </div>
    @endif
  </div>

  <div class="border-t hair p-4 bg-paper/70 backdrop-blur">
    {{-- "This taxpayer" mode: every question is about one VÖEN, which the server binds — never sent to the model. --}}
    <div class="max-w-[820px] mx-auto mb-2.5 flex items-center gap-2 flex-wrap text-sm" x-data="{ choosing: false }">
      @if($taxpayer)
        <span class="inline-flex items-center gap-2 rounded-xl bg-ink text-paper px-3 py-1.5">
          <span class="text-paper/70">{{ __('Taxpayer') }}:</span>
          <span class="font-medium">{{ $taxpayer['name'] ?? '—' }}</span>
          <span class="font-mono text-paper/80">VÖEN {{ $taxpayer['tin'] }}</span>
          <button type="button" wire:click="clearContext" class="text-paper/60 hover:text-paper" title="{{ __('Ask about all the data again') }}">✕</button>
        </span>
        <span class="text-faint text-xs">{{ __(':sold lines as seller · :bought as buyer · the VÖEN is not sent to the external model', ['sold' => number_format($taxpayer['sold'], 0, '.', ' '), 'bought' => number_format($taxpayer['bought'], 0, '.', ' ')]) }}</span>
        @if(! empty($messages))
          <span class="w-full flex flex-wrap gap-1.5">
            @foreach($taxpayerSuggestions as $s)
              <button type="button" wire:click="suggest(@js(__($s)))" class="chip px-2.5 py-1 text-xs">{{ __($s) }}</button>
            @endforeach
          </span>
        @endif
      @else
        <button type="button" x-show="! choosing" @click="choosing = true; $nextTick(() => $refs.tin.focus())" class="btn btn-ghost btn-sm">＋ {{ __('Ask about one taxpayer') }}</button>
        <form x-show="choosing" style="display: none" wire:submit="chooseTin" class="flex items-center gap-2">
          <input x-ref="tin" wire:model="tinInput" placeholder="{{ __('VÖEN, e.g. 1808172501') }}" autocomplete="off"
                 class="h-9 w-56 bg-surface border hair rounded-lg px-3 outline-none focus:border-ink font-mono text-sm">
          <button type="submit" class="btn btn-ink btn-sm">{{ __('Choose') }}</button>
          <button type="button" @click="choosing = false" class="text-faint hover:text-ink">✕</button>
        </form>
        @error('tinInput') <span class="text-stamp text-xs">{{ $message }}</span> @enderror
      @endif
    </div>
    <form wire:submit="ask" class="max-w-[820px] mx-auto flex items-center gap-2">
      <div class="flex-1 flex items-center gap-2 bg-surface border hair rounded-xl px-3.5 h-11 focus-within:border-ink transition">
        <input wire:model="question" autofocus autocomplete="off"
               placeholder="{{ $taxpayer ? __('Ask about this taxpayer…') : __('Ask a question about your invoices…') }}"
               class="w-full bg-transparent outline-none text-sm" wire:loading.attr="disabled">
      </div>
      <button type="submit" class="btn btn-ink h-11 px-5" wire:loading.attr="disabled" wire:target="ask">
        <span wire:loading.remove wire:target="ask">{{ __('Ask') }}</span>
        <span wire:loading wire:target="ask">…</span>
      </button>
    </form>
  </div>
</section>
