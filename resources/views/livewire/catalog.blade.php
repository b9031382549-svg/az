<section class="p-5 sm:p-8 max-w-[1000px]">
  <div class="mb-6 flex items-end justify-between flex-wrap gap-3">
    <div>
      <p class="kicker mb-1.5">{{ __('XİF MN · in memory') }}</p>
      <h1 class="font-display text-4xl">{{ __('Catalog (memory)') }}</h1>
      @isset($total)<p class="text-muted text-sm mt-1">{{ __(':n answers in memory', ['n' => number_format($total)]) }}</p>@endisset
    </div>
    <div class="flex items-center gap-2">
      <div class="flex items-center gap-2 bg-surface border hair rounded-xl px-3 h-10 w-64 max-w-full">
        <span class="text-faint">⌕</span>
        <input wire:model.live.debounce.300ms="q" placeholder="{{ __('Search product or heading…') }}" class="w-full bg-transparent outline-none text-sm">
      </div>
      @unless($search)
        <button wire:click="collapseAll" class="btn btn-ghost btn-sm">{{ __('Collapse all') }}</button>
      @endunless
    </div>
  </div>

  @if($search !== null)
    {{-- Flat search results --}}
    @php $hl = fn ($name) => preg_replace('/('.preg_quote($term, '/').')/iu', '<mark>$1</mark>', e($name)); @endphp
    <div class="card-flat overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-muted border-b hair bg-paper/50">
            <th class="font-medium px-4 py-3">{{ __('Product / service') }}</th>
            <th class="font-medium px-4 py-3">{{ __('Code') }}</th>
          </tr>
        </thead>
        <tbody>
          @forelse($search as $r)
            <tr wire:key="s-{{ $r->id }}" class="border-b hair last:border-0 hover:bg-paper/40 transition">
              <td class="px-4 py-3">
                <a href="{{ route('catalog.item', ['cache' => $r->id]) }}" wire:navigate class="link-under">{!! $hl($r->name) !!}</a>
              </td>
              <td class="px-4 py-3 font-mono whitespace-nowrap">{{ $r->is_service ? '99' : $r->heading }}</td>
            </tr>
          @empty
            <tr><td colspan="2" class="px-4 py-10 text-center text-muted">{{ __('Nothing in memory matches ":term".', ['term' => $term]) }}</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  @else
    {{-- The tree: chapter → position → leaf --}}
    <div class="card overflow-hidden">
      <div class="grid grid-cols-[1fr_auto] px-5 py-2.5 border-b hair">
        <span class="kicker">{{ __('Categories · positions') }}</span>
        <span class="kicker">{{ __('Positions quantity') }}</span>
      </div>

      @forelse($chapters as $ch)
        @php $chOpen = in_array($ch->code, $openChapters, true); @endphp
        <div class="border-b hair last:border-0">
          {{-- Chapter --}}
          <button type="button" wire:click="toggleChapter('{{ $ch->code }}')" wire:key="ch-{{ $ch->code }}"
                  class="w-full grid grid-cols-[1fr_auto] items-center gap-3 px-5 py-3 text-left hover:bg-paper/40 transition">
            <span class="flex items-center gap-2 min-w-0">
              <span class="text-faint w-4 shrink-0">{{ $chOpen ? '▾' : '▸' }}</span>
              @if($ch->code !== $servicesCode)<span class="font-mono text-xs text-faint shrink-0">{{ $ch->code }}</span>@endif
              <span class="font-medium truncate">{{ $ch->title }}</span>
            </span>
            <span class="tnum text-muted">{{ number_format($ch->count) }}</span>
          </button>

          {{-- Positions / service leaves --}}
          @if($chOpen)
            <div class="bg-paper/30">
              @if($ch->code === $servicesCode)
                @foreach($serviceLeaves as $leaf)
                  <a href="{{ route('catalog.item', ['cache' => $leaf->id]) }}" wire:navigate wire:key="sl-{{ $leaf->id }}"
                     class="grid grid-cols-[1fr_auto] items-center gap-3 pl-14 pr-5 py-2 hover:bg-paper/50 transition">
                    <span class="truncate text-sm link-under">{{ $leaf->name }}</span>
                    <span class="font-mono text-xs text-faint">99</span>
                  </a>
                @endforeach
              @else
                @foreach(($positionsByChapter[$ch->code] ?? []) as $pos)
                  @php $posOpen = in_array($pos->code, $openPositions, true); @endphp
                  <button type="button" wire:click="togglePosition('{{ $pos->code }}')" wire:key="pos-{{ $pos->code }}"
                          class="w-full grid grid-cols-[1fr_auto] items-center gap-3 pl-11 pr-5 py-2.5 text-left hover:bg-paper/50 transition">
                    <span class="flex items-center gap-2 min-w-0">
                      <span class="text-faint w-4 shrink-0">{{ $posOpen ? '▾' : '▸' }}</span>
                      <span class="font-mono text-xs text-faint shrink-0">{{ $pos->code }}</span>
                      <span class="truncate text-sm">{{ $pos->title }}</span>
                    </span>
                    <span class="tnum text-muted text-sm">{{ number_format($pos->count) }}</span>
                  </button>
                  @if($posOpen)
                    @foreach(($leavesByPosition[$pos->code] ?? []) as $leaf)
                      <a href="{{ route('catalog.item', ['cache' => $leaf->id]) }}" wire:navigate wire:key="lf-{{ $leaf->id }}"
                         class="grid grid-cols-[1fr_auto] items-center gap-3 pl-[4.5rem] pr-5 py-2 hover:bg-paper/60 transition">
                        <span class="truncate text-sm link-under">{{ $leaf->name }}</span>
                        <span class="font-mono text-xs text-faint">{{ $pos->code }}</span>
                      </a>
                    @endforeach
                  @endif
                @endforeach
              @endif
            </div>
          @endif
        </div>
      @empty
        <p class="px-5 py-12 text-center text-muted">{{ __('Memory is empty. Classify and confirm some items first.') }}</p>
      @endforelse
    </div>
  @endif
</section>
