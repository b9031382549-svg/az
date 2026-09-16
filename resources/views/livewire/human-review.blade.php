<section class="p-5 sm:p-8 max-w-[1180px]">
  @php
    $reasonLabel = fn ($r) => match ($r) {
        'conflict' => __('Mechanisms diverged'),
        'no_match' => __('No code proposed'),
        'blocked_on_fact' => __('Missing a fact'),
        'pending' => __('Still processing'),
        default => str_replace('_', ' ', (string) $r),
    };
    $reasonBadge = fn ($r) => match ($r) {
        'no_match' => 'bg-line/40 text-muted',
        'blocked_on_fact' => 'bg-amber/15 text-amber',
        default => 'bg-stamp/12 text-stamp',
    };
    $mechLabel = fn ($m) => match ($m) {
        'vector' => __('Vector'), 'broker' => __('Broker'), 'direct' => __('Direct recall'),
        'search' => __('Web search'), 'cache' => __('Memory'), 'ensemble' => __('Ensemble'),
        default => $m,
    };
    $fmtDate = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->diffForHumans() : '—';
  @endphp

  <div class="mb-6 flex items-end justify-between flex-wrap gap-3">
    <div>
      <p class="kicker mb-1.5">{{ __('XİF MN · goods & services') }}</p>
      <h1 class="font-display text-4xl">{{ __('Human review') }}</h1>
      <p class="text-muted text-sm mt-1">{{ __('Everything the classifier could not close on its own.') }}</p>
    </div>
    @if($uploads->isNotEmpty())
      <div class="flex items-center gap-2 text-sm">
        <span class="text-faint">{{ __('Upload') }}</span>
        <select wire:model.live="batch" class="h-9 px-2.5 rounded-lg border hair bg-surface outline-none hover:border-ink transition cursor-pointer max-w-[220px]">
          <option value="">{{ __('All uploads') }}</option>
          @foreach($uploads as $u)
            <option value="{{ $u->key }}">{{ \Illuminate\Support\Str::limit($u->label, 32) }} ({{ $u->open }})</option>
          @endforeach
        </select>
      </div>
    @endif
  </div>

  {{-- Tiles --}}
  <div class="grid grid-cols-3 gap-4 mb-6">
    <div class="card-flat p-4"><p class="kicker mb-1.5">{{ __('Waiting on a human') }}</p><p class="font-display text-2xl tnum">{{ number_format($waiting) }}</p></div>
    <div class="card-flat p-4"><p class="kicker mb-1.5">{{ __('Oldest in queue') }}</p><p class="font-display text-2xl">{{ $fmtDate($oldest) }}</p></div>
    <div class="card-flat p-4"><p class="kicker mb-1.5">{{ __('Processed today') }}</p><p class="font-display text-2xl tnum">{{ number_format($processedToday) }}</p></div>
  </div>

  <div class="grid lg:grid-cols-[minmax(0,360px)_1fr] gap-5">
    {{-- LEFT: the queue --}}
    <div class="card overflow-hidden self-start">
      <div class="px-4 py-3 border-b hair flex items-center justify-between">
        <p class="kicker">{{ __('Queue') }}</p>
        <span class="text-faint text-xs tnum">{{ $queue->count() }}</span>
      </div>
      <div class="max-h-[70vh] overflow-y-auto">
        @forelse($queue as $q)
          <button type="button" wire:click="selectItem({{ $q->id }})" wire:key="q-{{ $q->id }}"
                  class="w-full text-left px-4 py-3 border-b hair last:border-0 transition {{ $selected === $q->id ? 'bg-paper/70' : 'hover:bg-paper/40' }}">
            <div class="flex items-start gap-2">
              <span class="mt-0.5 w-1.5 h-1.5 rounded-full shrink-0 {{ $q->displayResolution() === 'no_match' ? 'bg-faint' : ($q->displayResolution() === 'blocked_on_fact' ? 'bg-amber' : 'bg-stamp') }}"></span>
              <div class="min-w-0">
                <p class="text-sm font-medium truncate">{{ $q->localizedSourceText() }}</p>
                <p class="text-faint text-xs mt-0.5">{{ $reasonLabel($q->displayResolution()) }}</p>
              </div>
            </div>
          </button>
        @empty
          <p class="px-4 py-10 text-center text-muted text-sm">{{ __('Nothing waiting. The queue is clear. 🎉') }}</p>
        @endforelse
      </div>
    </div>

    {{-- RIGHT: the decision panel --}}
    <div class="card p-6 self-start">
      @if($item)
        <div class="flex items-start justify-between gap-3 flex-wrap">
          <div class="min-w-0">
            <h2 class="font-display text-2xl break-words">{{ $item->localizedSourceText() }}</h2>
            @if($item->source_text !== $item->localizedSourceText())
              <p class="text-muted text-sm mt-1">{{ $item->source_text }}</p>
            @endif
          </div>
          <span class="hint-head px-2 py-0.5 rounded-md text-xs font-medium whitespace-nowrap {{ $reasonBadge($item->displayResolution()) }}"
                data-hint="{{ $item->displayResolution() }}">{{ $reasonLabel($item->displayResolution()) }}</span>
        </div>

        {{-- What each mechanism proposed --}}
        <div class="mt-5">
          <p class="kicker mb-2">{{ __('What the mechanisms proposed') }}</p>
          @if($proposals->isNotEmpty())
            <div class="flex flex-wrap gap-2">
              @foreach($proposals as $p)
                <span class="chip px-2.5 py-1 text-sm">
                  <span class="text-faint">{{ $mechLabel($p->mechanism) }}</span>
                  <span class="font-mono">{{ $p->heading }}</span>
                  <span class="text-muted">{{ \Illuminate\Support\Str::limit($headingNames[$p->heading] ?? '', 28) }}</span>
                </span>
              @endforeach
            </div>
          @else
            <p class="text-muted text-sm">{{ __('No mechanism produced a code — decide from the item name.') }}</p>
          @endif
        </div>

        {{-- Pick a candidate heading --}}
        @if($candidates->isNotEmpty())
          <div class="mt-5">
            <p class="kicker mb-2">{{ __('Confirm a heading') }}</p>
            <div class="flex flex-col gap-1.5">
              @foreach($candidates as $code)
                <button type="button" wire:click="confirm('{{ $code }}')"
                        class="w-full flex items-center gap-3 text-left px-3 py-2 rounded-lg border hair hover:border-ink bg-surface transition">
                  <span class="font-mono text-sm">{{ $code }}</span>
                  <span class="text-muted text-sm truncate">{{ $headingNames[$code] ?? '—' }}</span>
                  <span class="ml-auto text-ledger text-sm shrink-0">✓ {{ __('Confirm') }}</span>
                </button>
              @endforeach
            </div>
          </div>
        @endif

        {{-- Manual code + actions --}}
        <div class="mt-5 flex flex-wrap items-end gap-3">
          <div>
            <label class="field-label">{{ __('Or a 4-digit heading') }}</label>
            <div class="flex items-center gap-2">
              <input wire:model="manualCode" wire:keydown.enter="confirmManual" placeholder="0000"
                     class="field-input font-mono w-28 h-10" maxlength="10">
              <button wire:click="confirmManual" class="btn btn-ink btn-sm">{{ __('Confirm') }}</button>
            </div>
          </div>
          <div class="flex items-center gap-2 ml-auto">
            <button wire:click="skip" class="btn btn-ghost btn-sm">{{ __('Skip') }}</button>
            <button wire:click="rejectItem" wire:confirm="{{ __('Reject this item? It keeps no final code.') }}"
                    class="btn btn-ghost btn-sm text-stamp">{{ __('Reject') }}</button>
          </div>
        </div>

        <div class="mt-4 pt-4 border-t hair">
          <a href="{{ route('review.decision', ['item' => $item->id, 'from' => 'human']) }}" target="_blank"
             class="text-sm text-muted hover:text-ink link-under">{{ __('Open the full decision trace →') }}</a>
        </div>
      @else
        <div class="py-16 text-center">
          <p class="font-display text-2xl mb-1">{{ __('All clear') }}</p>
          <p class="text-muted text-sm">{{ __('No items are waiting for a human right now.') }}</p>
        </div>
      @endif
    </div>
  </div>
</section>
