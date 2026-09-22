<section class="p-5 sm:p-8 max-w-[1080px]">
  <div class="mb-6 flex items-end justify-between flex-wrap gap-3">
    <div>
      <h1 class="font-display text-4xl">{{ __('Review queue') }}</h1>
      <p class="text-muted text-sm mt-1">{{ __('Pick an upload to see its report and items.') }}</p>
    </div>
    <div class="flex items-center gap-2 text-sm flex-wrap">
      <div class="flex items-center gap-2 bg-surface border hair rounded-lg px-3 h-9 w-56 max-w-full">
        <span class="text-faint">⌕</span>
        <input wire:model.live.debounce.300ms="q" placeholder="{{ __('Search uploads…') }}" class="w-full bg-transparent outline-none text-sm">
      </div>
      <span class="text-faint">{{ __('Rows') }}</span>
      <select wire:model.live="perPage" class="h-9 px-2.5 rounded-lg border hair bg-surface outline-none hover:border-ink transition cursor-pointer tnum">
        <option value="10">10</option>
        <option value="25">25</option>
        <option value="50">50</option>
      </select>
    </div>
  </div>

  <div class="card overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b hair">
            <th class="kicker font-medium text-left px-5 py-2.5">{{ __('Upload') }}</th>
            <th class="kicker font-medium text-left px-5 py-2.5">{{ __('Date') }}</th>
            <th class="kicker font-medium text-right px-5 py-2.5">{{ __('Items') }}</th>
            <th class="kicker font-medium text-right px-5 py-2.5"><span class="hint-head" data-hint="column:memory">{{ __('To Memory') }}</span></th>
            <th class="kicker font-medium text-left px-5 py-2.5"><span class="hint-head" data-hint="column:result">{{ __('Result') }}</span></th>
          </tr>
        </thead>
        <tbody>
          {{-- All uploads — pinned, exact sums across every upload (hidden while searching). --}}
          @if(trim($q) === '')
          <tr wire:key="up-all" class="border-b hair bg-paper/40">
            <td class="px-5 py-3">
              <a href="{{ route('review.batch', ['batch' => 'all']) }}" wire:navigate class="flex items-center gap-2 min-w-0 font-semibold hover:text-stamp transition">
                <span class="text-faint shrink-0">🗂</span>{{ __('All uploads') }}
              </a>
            </td>
            <td class="px-5 py-3 text-muted">—</td>
            <td class="px-5 py-3 text-right tnum">{{ number_format($allRow->total) }}</td>
            <td class="px-5 py-3 text-right tnum">
              {{ number_format($allRow->memory) }}
              @if($allRow->total > 0)<span class="text-faint text-xs">· {{ (int) round($allRow->memory / $allRow->total * 100) }}%</span>@endif
            </td>
            <td class="px-5 py-3 text-faint text-xs">{{ __('everything') }}</td>
          </tr>
          @endif
          {{-- One row per upload --}}
          @forelse($uploads as $u)
            @php
              $wc = $u->total ? $u->resolved / $u->total * 100 : 0;
              $wr = $u->total ? $u->review / $u->total * 100 : 0;
              $ws = $u->total ? ($u->resolving ?? 0) / $u->total * 100 : 0;
              $wk = $u->total ? $u->conflict / $u->total * 100 : 0;
            @endphp
            <tr wire:key="up-{{ $u->key }}" class="border-b hair last:border-0 hover:bg-paper/40 transition">
              <td class="px-5 py-3">
                <a href="{{ route('review.batch', ['batch' => $u->key]) }}" wire:navigate class="flex items-center gap-2 min-w-0 font-medium hover:text-stamp transition">
                  <span class="text-faint shrink-0">📄</span>
                  <span class="truncate">{{ \Illuminate\Support\Str::limit($u->label, 42) }}</span>
                </a>
              </td>
              <td class="px-5 py-3 text-muted tnum whitespace-nowrap">{{ $u->last_at ? \Illuminate\Support\Carbon::parse($u->last_at)->format('Y-m-d') : '—' }}</td>
              <td class="px-5 py-3 text-right tnum">{{ number_format($u->total) }}</td>
              <td class="px-5 py-3 text-right tnum">
                {{ number_format($u->memory) }}
                @if($u->total > 0 && $u->memory > 0)<span class="text-faint text-xs">· {{ (int) round($u->memory / $u->total * 100) }}%</span>@endif
              </td>
              <td class="px-5 py-3">
                <div class="flex items-center gap-2.5">
                  <span class="w-24 h-2 rounded-full bg-line/40 overflow-hidden flex shrink-0 cursor-help"
                        data-hint-title="{{ __('Result') }}"
                        data-hint-body="{{ __(':resolved resolved · :searching searching · :review needs attention · :conflict conflict', ['resolved' => $u->resolved, 'searching' => $u->resolving ?? 0, 'review' => $u->review, 'conflict' => $u->conflict]) }}"
                        data-hint-meta="{{ __(':done% of :total resolved.', ['done' => $u->done, 'total' => $u->total]) }}">
                    <span class="bg-ledger block h-full" style="width:{{ $wc }}%"></span>
                    <span class="bg-amber block h-full" style="width:{{ $wr }}%"></span>
                    <span class="bg-amber/50 block h-full animate-pulse" style="width:{{ $ws }}%"></span>
                    <span class="bg-stamp block h-full" style="width:{{ $wk }}%"></span>
                  </span>
                  <span class="text-faint tnum text-xs whitespace-nowrap">{{ $u->done }}% {{ __('resolved') }}</span>
                </div>
              </td>
            </tr>
          @empty
            <tr><td colspan="5" class="px-5 py-10 text-center text-muted">
              {{ trim($q) !== '' ? __('No uploads match ":term".', ['term' => $q]) : __('No uploads yet. Classify some items first.') }}
            </td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    @if($uploadPages > 1)
      <div class="flex items-center justify-between px-5 py-3 border-t hair">
        <span class="text-xs text-faint tnum">{{ $uploadStart + 1 }}–{{ min($uploadStart + $perPage, $uploadTotal) }} {{ __('of') }} {{ $uploadTotal }}</span>
        <div class="flex items-center gap-1 text-sm">
          <button wire:click="setUploadPage({{ max(1, $uploadPage - 1) }})"
                  class="px-2.5 py-1 rounded-lg border hair bg-surface {{ $uploadPage === 1 ? 'text-faint opacity-50 pointer-events-none' : 'hover:border-ink' }}">‹</button>
          @for($p = 1; $p <= $uploadPages; $p++)
            <button wire:click="setUploadPage({{ $p }})"
                    class="px-3 py-1 rounded-lg border {{ $p === $uploadPage ? 'border-ink bg-ink text-paper' : 'hair bg-surface hover:border-ink' }}">{{ $p }}</button>
          @endfor
          <button wire:click="setUploadPage({{ min($uploadPages, $uploadPage + 1) }})"
                  class="px-2.5 py-1 rounded-lg border hair bg-surface {{ $uploadPage === $uploadPages ? 'text-faint opacity-50 pointer-events-none' : 'hover:border-ink' }}">›</button>
        </div>
      </div>
    @endif
  </div>
</section>
