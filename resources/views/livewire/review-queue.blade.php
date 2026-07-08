<section class="p-5 sm:p-8 max-w-[1080px]">
  @php
    // Everything resolves at the 4-digit HS heading now — one view, no full/heading toggle.
    $tabs = ['open' => __('Needs attention'), 'found' => __('Found'), 'confirmed' => __('Confirmed'), 'rejected' => __('Rejected'), 'no_match' => __('No match'), 'all' => __('All')];
    $kindBadge = fn ($k) => $k === 'service' ? 'bg-amber/15 text-amber' : ($k === 'good' ? 'bg-ledger/12 text-ledger' : 'bg-line/40 text-muted');
    $resBadge = fn ($s) => match ($s) {
        'agreed', 'confirmed' => 'bg-ledger/12 text-ledger',
        'ai_resolved' => 'bg-ink/10 text-ink',
        'blocked_on_fact' => 'bg-amber/15 text-amber',
        'conflict' => 'bg-stamp/12 text-stamp',
        default => 'bg-line/40 text-muted',
    };
    $tabCount = fn ($key) => $key === 'all' ? $counts->sum() : ($key === 'open' ? $openCount : ($counts[$key] ?? 0));
  @endphp

  @php $batchLabels = $batches->keyBy('key'); @endphp

  <div class="mb-6 flex items-end justify-between flex-wrap gap-3">
    <div>
      <p class="kicker mb-1.5">{{ __('Quality control') }}</p>
      <h1 class="font-display text-4xl">{{ __('Review queue') }}</h1>
    </div>
    <div class="flex items-center gap-3 flex-wrap">
      <a href="{{ route('review.export', ['batch' => $batch, 'filter' => $filter]) }}"
         class="btn btn-ghost btn-sm" title="{{ __('Export the current view (upload + status filter) to Excel') }}">⬇ {{ __('Export Excel') }}</a>
    </div>
  </div>

  {{-- Uploads — pick which import to review (replaces the old dropdown). --}}
  <div class="card mb-5 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b hair">
            <th class="kicker font-medium text-left px-5 py-2.5">{{ __('Upload') }}</th>
            <th class="kicker font-medium text-left px-5 py-2.5">{{ __('Date') }}</th>
            <th class="kicker font-medium text-right px-5 py-2.5">{{ __('Items') }}</th>
            <th class="kicker font-medium text-left px-5 py-2.5">{{ __('Result') }}</th>
          </tr>
        </thead>
        <tbody>
          {{-- All uploads --}}
          <tr wire:click="selectBatch('all')" class="cursor-pointer border-b hair transition {{ $batch === 'all' ? 'bg-paper/70' : 'hover:bg-paper/40' }}">
            <td class="px-5 py-3">
              <div class="flex items-center gap-2 min-w-0">
                <span class="text-faint shrink-0">🗂</span>
                <span class="{{ $batch === 'all' ? 'font-semibold' : 'font-medium' }}">{{ __('All uploads') }}</span>
                @if($batch === 'all')<span class="text-[10px] px-1.5 py-0.5 rounded bg-ink text-paper shrink-0">{{ __('viewing') }}</span>@endif
              </div>
            </td>
            <td class="px-5 py-3 text-muted">—</td>
            <td class="px-5 py-3 text-right tnum">{{ $batches->sum('total') }}</td>
            <td class="px-5 py-3 text-faint text-xs">{{ __('everything') }}</td>
          </tr>
          {{-- One row per upload --}}
          @foreach($uploads as $u)
            @php
              $wc = $u->total ? $u->resolved / $u->total * 100 : 0;
              $wr = $u->total ? $u->review / $u->total * 100 : 0;
              $wk = $u->total ? $u->conflict / $u->total * 100 : 0;
            @endphp
            <tr wire:click="selectBatch('{{ $u->key }}')" wire:key="up-{{ $u->key }}"
                class="cursor-pointer border-b hair last:border-0 transition {{ $batch === (string) $u->key ? 'bg-paper/70' : 'hover:bg-paper/40' }}">
              <td class="px-5 py-3">
                <div class="flex items-center gap-2 min-w-0">
                  <span class="text-faint shrink-0">📄</span>
                  <span class="truncate {{ $batch === (string) $u->key ? 'font-semibold' : 'font-medium' }}">{{ \Illuminate\Support\Str::limit($u->label, 42) }}</span>
                  @if($batch === (string) $u->key)<span class="text-[10px] px-1.5 py-0.5 rounded bg-ink text-paper shrink-0">{{ __('viewing') }}</span>@endif
                </div>
              </td>
              <td class="px-5 py-3 text-muted tnum whitespace-nowrap">{{ $u->last_at ? \Illuminate\Support\Carbon::parse($u->last_at)->format('Y-m-d') : '—' }}</td>
              <td class="px-5 py-3 text-right tnum">{{ $u->total }}</td>
              <td class="px-5 py-3">
                <div class="flex items-center gap-2.5">
                  <span class="w-24 h-2 rounded-full bg-line/40 overflow-hidden flex shrink-0">
                    <span class="bg-ledger block h-full" style="width:{{ $wc }}%"></span>
                    <span class="bg-amber block h-full" style="width:{{ $wr }}%"></span>
                    <span class="bg-stamp block h-full" style="width:{{ $wk }}%"></span>
                  </span>
                  <span class="text-faint tnum text-xs whitespace-nowrap">{{ $u->done }}% {{ __('resolved') }}</span>
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @if($uploadPages > 1)
      <div class="flex items-center justify-between px-5 py-3 border-t hair">
        <span class="text-xs text-faint tnum">{{ $uploadStart + 1 }}–{{ min($uploadStart + 5, $uploadTotal) }} {{ __('of') }} {{ $uploadTotal }}</span>
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

  {{-- Distribution report --}}
  @php
    $chName = [
      '03'=>'Fish','04'=>'Dairy','07'=>'Vegetables','08'=>'Fruit','09'=>'Coffee/tea','11'=>'Milling',
      '15'=>'Fats/oils','16'=>'Meat/fish prep','17'=>'Sugar/sweets','18'=>'Cocoa','19'=>'Bakery',
      '20'=>'Veg/fruit prep','21'=>'Food prep','22'=>'Beverages','25'=>'Salt/stone','28'=>'Inorg. chem',
      '30'=>'Pharma','32'=>'Dyes/paint','33'=>'Cosmetics','34'=>'Soap/cleaning','38'=>'Chemicals',
      '39'=>'Plastics','40'=>'Rubber','42'=>'Leather goods','48'=>'Paper','49'=>'Printed','61'=>'Knit apparel',
      '62'=>'Apparel','63'=>'Textiles','64'=>'Footwear','69'=>'Ceramics','70'=>'Glass','73'=>'Steel articles',
      '76'=>'Aluminium','82'=>'Tools','84'=>'Machinery','85'=>'Electrical','90'=>'Medical/optical',
      '94'=>'Furniture/lamps','95'=>'Toys','96'=>'Misc. mfg','99'=>'Services',
    ];
    $cs = $report['consensus']; $csTotal = max(1, $report['total']);
    $gs = $report['good'] + $report['service'];
    $maxCh = max(1, optional($report['chapters']->first())->c ?? 1);
  @endphp
  <div x-data="{open:true}" class="card p-5 mb-5">
    <button @click="open=!open" class="w-full flex items-center justify-between">
      <span class="kicker">{{ __('Report') }}{{ $batch !== 'all' ? ' · '.__('this upload') : '' }}</span>
      <span class="text-faint text-sm" x-text="open ? '▾ hide' : '▸ show'"></span>
    </button>

    <div x-show="open" class="mt-4 grid lg:grid-cols-3 gap-7">
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

      {{-- Top HS chapters --}}
      <div>
        <p class="kicker mb-2">{{ __('Top categories (HS chapter)') }}</p>
        @forelse($report['chapters'] as $ch)
          <div class="flex items-center gap-2 text-sm mb-1.5">
            <span class="w-28 shrink-0 truncate">{{ $chName[$ch->chapter] ?? ('Ch '.$ch->chapter) }}</span>
            <span class="flex-1 h-2 rounded-full bg-line/40 overflow-hidden"><span class="bg-ink/70 block h-full" style="width:{{ $ch->c/$maxCh*100 }}%"></span></span>
            <span class="tnum text-faint w-8 text-right">{{ $ch->c }}</span>
          </div>
        @empty
          <p class="text-muted text-sm">{{ __('No classified codes yet.') }}</p>
        @endforelse
      </div>
    </div>
  </div>

  <div class="flex flex-wrap gap-2 mb-3">
    @foreach($tabs as $key => $label)
      <button wire:click="setFilter('{{ $key }}')"
              class="px-3 py-1.5 rounded-lg text-sm border hair transition {{ $filter === $key ? 'bg-ink text-paper border-ink' : 'bg-surface hover:border-ink' }}">
        {{ $label }}
        <span class="opacity-60">{{ $tabCount($key) }}</span>
      </button>
    @endforeach
  </div>

  {{-- Bulk actions for a single upload --}}
  @if($batch !== 'all')
    <div class="flex flex-wrap items-center gap-2 mb-5">
      <span class="text-sm text-muted">{{ __('This upload:') }}</span>
      <button wire:click="confirmAll" wire:confirm="Confirm all agreed items in this upload?"
              @disabled($actionableCount === 0)
              class="btn btn-ghost btn-sm {{ $actionableCount === 0 ? 'opacity-40 cursor-not-allowed' : '' }}">✓ {{ __('Confirm agreed') }}</button>
      <button wire:click="rejectAll" wire:confirm="Reject all {{ $actionableCount }} open items in this upload?"
              @disabled($actionableCount === 0)
              class="btn btn-ghost btn-sm {{ $actionableCount === 0 ? 'opacity-40 cursor-not-allowed' : '' }}">✕ {{ __('Reject all') }} ({{ $actionableCount }})</button>
      <button wire:click="deleteBatch" wire:confirm="Delete this entire upload and all its items? This cannot be undone."
              class="btn btn-ghost btn-sm text-stamp ml-auto">🗑 {{ __('Delete upload') }}</button>
    </div>
  @endif

  <div class="space-y-3">
    @forelse($items as $item)
      @php
        $editable = in_array($item->resolution, ['agreed','conflict','blocked_on_fact','confirmed','ai_resolved'], true);
        $allowed = $item->allowedCodes();
        // Correct-code options — 4-digit HS headings only (the item's own answer + each
        // mechanism's proposed heading), de-duplicated and sorted by number.
        $options = collect([$item->final_code])->filter()->merge($allowed)
            ->map(fn ($c) => (string) mb_substr((string) $c, 0, 4))
            ->filter()->unique()->sortBy(fn ($c) => (int) $c)->values();
        $default = (string) ($item->final_code ?? ($options->first() ?? ''));
        $badgeLabel = __(str_replace('_', ' ', $item->resolution));
        $badgeClass = $resBadge($item->resolution);
      @endphp
      <div wire:key="item-{{ $item->id }}" class="card-flat p-4 flex items-start gap-4 flex-wrap sm:flex-nowrap">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 mb-1 flex-wrap">
            <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $badgeClass }}">{{ $badgeLabel }}</span>
            <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $kindBadge($item->kind) }}">{{ $item->kind ?? '—' }}</span>
            <span class="font-mono text-sm">{{ $item->final_code ?? __('—') }}</span>
            @if(mb_strlen((string) $item->final_code) === 4)<span class="px-1.5 py-0.5 rounded text-[10px] bg-line/40 text-muted" title="{{ __('resolved at the 4-digit heading; exact code left to a human') }}">{{ __('heading') }}</span>@endif
            @if($batch === 'all' && $item->batch)
              <span class="px-2 py-0.5 rounded-md text-xs bg-line/40 text-muted">{{ \Illuminate\Support\Str::limit(optional($batchLabels->get($item->batch))->label ?? __('Earlier import'), 26) }}</span>
            @endif
          </div>
          <p class="font-medium">{{ $item->localizedSourceText() }}</p>
          @if($item->finalCode)
            <p class="text-muted text-sm mt-0.5">{{ Str::limit($item->finalCode->localizedName(), 110) }}</p>
          @elseif(($nlen = mb_strlen((string) $item->final_code)) > 0 && $nlen < 10 && isset($headingNames[(string) $item->final_code]))
            <p class="text-muted text-sm mt-0.5">{{ Str::limit($headingNames[(string) $item->final_code], 110) }} <span class="text-faint">· {{ (string) $item->final_code === '99' ? __('service level') : __('heading only') }}</span></p>
          @endif

          {{-- Decision source — the pipeline that produced the answer: memory (cache) →
               local ai (the on-box models) → web research. The step that RESOLVED it is
               highlighted; a step that was tried but passed the item on is struck through;
               a step never reached is faint. --}}
          @php
            $cacheRow  = $item->results->firstWhere('mechanism', 'cache');
            $mechRan   = $item->results->whereIn('mechanism', ['vector', 'broker', 'direct'])->isNotEmpty();
            $searchRow = $item->results->firstWhere('mechanism', 'search');

            $sMemory = $cacheRow !== null ? 'ok' : 'fail';
            $sLocal = match (true) {
                $cacheRow !== null => 'skip',                                                          // memory already answered
                $item->final_code && $item->resolution !== 'ai_resolved' && $mechRan => 'ok',           // consensus resolved
                $mechRan => 'fail',                                                                     // ran, no consensus
                default => 'skip',
            };
            $sWeb = match (true) {
                $sMemory === 'ok' || $sLocal === 'ok' => 'skip',                                        // resolved earlier
                $item->resolution === 'ai_resolved' => 'ok',                                            // web search resolved
                $searchRow !== null => 'fail',                                                          // searched, not confident
                default => 'skip',                                                                      // never reached
            };
            $chipClass = fn ($st) => match ($st) {
                'ok' => 'bg-ledger/12 text-ledger font-medium',
                'fail' => 'text-faint line-through',
                default => 'text-faint/50',
            };
            $flow = [['memory', $sMemory], ['local ai', $sLocal], ['web research', $sWeb]];
          @endphp
          <div class="flex flex-col gap-1 mt-2">
            <div class="flex items-center gap-1.5 flex-wrap text-xs">
              @foreach($flow as $i => [$label, $state])
                @if($i > 0)<span class="text-faint/50">→</span>@endif
                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded {{ $chipClass($state) }}">{{ $state === 'ok' ? '✓' : ($state === 'fail' ? '✕' : '·') }} {{ __($label) }}</span>
              @endforeach
            </div>
          </div>

          <a href="{{ route('review.decision', $item->id) }}" target="_blank"
             class="inline-block mt-2 text-xs text-muted hover:text-ink underline decoration-dotted">🔍 {{ __('Decision flow') }}</a>
        </div>

        <div class="shrink-0 w-full sm:w-[340px]">
          @if($editable && $options->isNotEmpty())
            <div x-data="{ code: @js($default) }">
              <select x-model="code"
                      class="w-full px-2.5 py-1.5 rounded-lg text-xs border hair bg-surface focus:border-ink outline-none mb-2">
                @foreach($options as $c)
                  @php $optName = $headingNames[$c] ?? ''; @endphp
                  <option value="{{ $c }}">{{ $c }} · {{ \Illuminate\Support\Str::limit($optName, 44) }}{{ (string) $c === (string) $item->final_code ? '  ← final' : '' }}</option>
                @endforeach
              </select>
              <div class="flex gap-2 justify-end">
                <button wire:click="reject({{ $item->id }})" class="btn btn-ghost btn-sm">✕ {{ __('Reject') }}</button>
                <button x-on:click="$wire.confirmWith({{ $item->id }}, code)"
                        class="btn btn-ink btn-sm"
                        x-text="'✓ ' + (code === @js((string) $item->final_code) ? @js(__('Confirm')) : @js(__('Save fix')))"></button>
              </div>
            </div>
          @else
            <span class="text-xs text-faint">{{ __(str_replace('_',' ',$item->resolution)) }}</span>
          @endif
        </div>
      </div>
    @empty
      <div class="card-flat p-10 text-center text-muted">{{ __('Nothing here. Classify some items first.') }}</div>
    @endforelse
  </div>

  <div class="mt-5">{{ $items->onEachSide(1)->links() }}</div>
</section>
