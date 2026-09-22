<section class="p-5 sm:p-8 max-w-[1080px]">
  @php
    // All (default) + the human-facing outcome filters. "Needs attention"/"No match"
    // stay reachable via the donut segments below; the dedicated human queue is a
    // separate page. The 'Searching…' tab only appears while conflicts are still
    // under the web-search resolver.
    $tabs = ['all' => __('All'), 'found' => __('AI classified'), 'confirmed' => __('Human confirmed'), 'rejected' => __('Human rejected')];
    if (($counts['resolving'] ?? 0) > 0) { $tabs['resolving'] = __('Searching…'); }
    $tabCount = fn ($key) => $key === 'all' ? $counts->sum() : ($key === 'open' ? $openCount : ($counts[$key] ?? 0));
    $cs = $report['consensus']; $csTotal = max(1, $report['total']);
    $gs = $report['good'] + $report['service'];
  @endphp

  <div class="mb-6">
    <a href="{{ route('review') }}" wire:navigate class="text-sm text-muted hover:text-ink">← {{ __('Back to uploads') }}</a>
    <div class="flex items-end justify-between flex-wrap gap-3 mt-3">
      <h1 class="font-display text-4xl">{{ $batchLabel }}</h1>
      <a href="{{ route('review.export', ['batch' => $batch, 'filter' => $filter]) }}"
         class="btn btn-ghost btn-sm" title="{{ __('Export the current view (upload + status filter) to Excel') }}">⬇ {{ __('Export Excel') }}</a>
    </div>
  </div>

  {{-- Per-batch funnel + recognition time (single upload only). --}}
  @if($batchStats)
    <div class="mb-5">
      @include('livewire.partials.batch-stats', ['stats' => $batchStats])
    </div>
  @endif

  <div x-data="{open:true}" class="card p-5 mb-5">
    <button @click="open=!open" class="w-full flex items-center justify-end">
      <span class="text-faint text-sm" x-text="open ? '▾ hide' : '▸ show'"></span>
    </button>

    <div x-show="open" class="mt-4 grid lg:grid-cols-2 gap-7">
      {{-- Resolution donut --}}
      <div class="flex items-center gap-4">
        <div class="relative shrink-0" style="width:120px;height:120px">
          <svg viewBox="0 0 120 120" width="120" height="120">
            <circle cx="60" cy="60" r="{{ $report['donut']['r'] }}" fill="none" stroke="#ece6d9" stroke-width="12"/>
            @foreach($report['donut']['segments'] as $s)
              <circle cx="60" cy="60" r="{{ $report['donut']['r'] }}" fill="none"
                      stroke="{{ $s['color'] }}" stroke-width="12" stroke-linecap="butt"
                      stroke-dasharray="{{ $s['len'] }} {{ $s['gap'] }}"
                      stroke-dashoffset="{{ $s['offset'] }}"
                      transform="rotate(-90 60 60)"/>
            @endforeach
          </svg>
          <div class="absolute inset-0 grid place-items-center text-center">
            <div>
              <div class="font-display text-2xl leading-none tnum">{{ number_format($report['total']) }}</div>
              <div class="text-faint text-[11px]">{{ __('items') }}</div>
            </div>
          </div>
        </div>
        <div class="space-y-1.5 text-sm min-w-0 flex-1">
          @forelse($report['donut']['segments'] as $s)
            <button type="button" wire:click="setFilter('{{ $s['key'] }}')"
                    class="w-full flex items-center gap-2 text-left rounded px-1 -mx-1 hover:bg-paper/60 transition {{ $filter === $s['key'] ? 'font-medium' : '' }}">
              <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:{{ $s['color'] }}"></span>
              <span class="truncate">{{ __($s['label']) }}</span>
              <span class="text-faint tnum ml-auto whitespace-nowrap">{{ $s['count'] }} · {{ $s['pct'] }}%</span>
            </button>
          @empty
            <p class="text-muted">{{ __('No items yet.') }}</p>
          @endforelse
        </div>
      </div>

      {{-- Good/service + consensus --}}
      <div class="space-y-4">
        <div>
          <p class="kicker mb-2">{{ __('Good vs service') }}</p>
          <div class="flex h-3 rounded-full overflow-hidden bg-line/40">
            <div class="bg-ledger h-full" style="width:{{ $gs ? $report['good']/$gs*100 : 0 }}%"></div>
            <div class="bg-amber h-full" style="width:{{ $gs ? $report['service']/$gs*100 : 0 }}%"></div>
          </div>
          <div class="flex justify-between text-sm mt-1.5">
            <span class="text-ledger">● {{ __('Goods') }} <span class="tnum">{{ $report['good'] }}</span></span>
            <span class="text-amber"><span class="tnum">{{ $report['service'] }}</span> {{ __('Services') }} ●</span>
          </div>
        </div>
        <div>
          <p class="kicker mb-2">{{ __('Mechanism consensus') }}</p>
          <div class="space-y-1.5">
            @foreach([[__('Found'),$cs['found'] ?? 0,'bg-ledger'],[__('Review'),$cs['review'],'bg-amber'],[__('Conflict'),$cs['conflict'],'bg-stamp']] as [$lbl,$val,$bar])
              <div class="flex items-center gap-2 text-sm">
                <span class="w-28 shrink-0 text-muted">{{ $lbl }}</span>
                <span class="flex-1 h-2 rounded-full bg-line/40 overflow-hidden"><span class="{{ $bar }} block h-full" style="width:{{ $val/$csTotal*100 }}%"></span></span>
                <span class="tnum text-faint w-8 text-right">{{ $val }}</span>
              </div>
            @endforeach
          </div>
        </div>
      </div>
    </div>
  </div>

  @php $needsHuman = (int) ($counts['conflict'] ?? 0) + (int) ($counts['no_match'] ?? 0) + (int) ($counts['blocked_on_fact'] ?? 0); @endphp
  <div class="flex flex-wrap items-center gap-2 mb-3">
    @foreach($tabs as $key => $label)
      <button wire:click="setFilter('{{ $key }}')"
              class="px-3 py-1.5 rounded-lg text-sm border hair transition {{ $filter === $key ? 'bg-ink text-paper border-ink' : 'bg-surface hover:border-ink' }}">
        {{ $label }}
        <span class="opacity-60">{{ $tabCount($key) }}</span>
      </button>
    @endforeach
    @if($needsHuman > 0)
      <a href="{{ route('human-review', $batch !== 'all' ? ['batch' => $batch] : []) }}" wire:navigate
         class="ml-auto text-sm text-stamp hover:underline">{{ __('Needs attention') }} ({{ $needsHuman }}) →</a>
    @endif
  </div>

  {{-- Live search by item name (original + en/ru translation). --}}
  <div class="mb-3 flex items-center gap-2 bg-surface border hair rounded-lg px-3 h-9 w-72 max-w-full">
    <span class="text-faint">⌕</span>
    <input wire:model.live.debounce.300ms="q" placeholder="{{ __('Search items…') }}" class="w-full bg-transparent outline-none text-sm">
  </div>

  {{-- Results table (shared with the Classify page). Confirm/reject moved to the
       decision page — the item name links there. --}}
  @include('livewire.partials.results-table', ['rows' => $items, 'headingNames' => $headingNames, 'from' => 'review'])

  <div class="mt-5">{{ $items->onEachSide(1)->links() }}</div>
</section>
