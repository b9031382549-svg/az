<section class="flex flex-col h-[calc(100vh-4rem)]">

  <div class="flex-1 overflow-y-auto p-5 sm:p-8">
    @if(empty($messages))
      <div class="h-full grid place-items-center text-center">
        <div class="max-w-lg">
          <div class="mx-auto w-12 h-12 grid place-items-center rounded-2xl bg-ink text-paper font-display text-xl mb-4">✦</div>
          <h1 class="font-display text-3xl mb-2">Ask about your invoices</h1>
          <p class="text-muted mb-7">Plain-language questions are translated into read-only SQL and run against your data.</p>
          <div class="flex flex-wrap justify-center gap-2">
            @foreach($suggestions as $s)
              <button wire:click="suggest('{{ $s }}')"
                      class="btn btn-ghost btn-sm">{{ $s }}</button>
            @endforeach
          </div>
        </div>
      </div>
    @else
      <div class="max-w-[820px] mx-auto">
        <div class="flex justify-end mb-3">
          <button wire:click="clearHistory" wire:confirm="Clear your entire chat history?"
                  class="btn btn-ghost btn-sm text-muted">Clear history</button>
        </div>
        <div class="space-y-6">
        @foreach($messages as $m)
          <div class="flex justify-end">
            <div class="bg-ink text-paper rounded-2xl rounded-br-sm px-4 py-2.5 max-w-[80%]">{{ $m['q'] }}</div>
          </div>

          <div class="card p-5">
            @if($m['error'])
              <p class="text-stamp text-sm"><span class="font-medium">Could not answer:</span> {{ $m['error'] }}</p>
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
                              <td class="px-3.5 py-2.5 tnum whitespace-nowrap">{{ $row[$col] }}</td>
                            @endforeach
                          </tr>
                        @endforeach
                      </tbody>
                    </table>
                  </div>
                </div>
                @if($m['truncated'])
                  <p class="text-faint text-xs mt-2">Showing the first 50 rows.</p>
                @endif
              @else
                <p class="text-muted text-sm">No rows returned.</p>
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
    <form wire:submit="ask" class="max-w-[820px] mx-auto flex items-center gap-2">
      <div class="flex-1 flex items-center gap-2 bg-surface border hair rounded-xl px-3.5 h-11 focus-within:border-ink transition">
        <input wire:model="question" autofocus autocomplete="off"
               placeholder="Ask a question about your invoices…"
               class="w-full bg-transparent outline-none text-sm" wire:loading.attr="disabled">
      </div>
      <button type="submit" class="btn btn-ink h-11 px-5" wire:loading.attr="disabled" wire:target="ask">
        <span wire:loading.remove wire:target="ask">Ask</span>
        <span wire:loading wire:target="ask">…</span>
      </button>
    </form>
  </div>
</section>
