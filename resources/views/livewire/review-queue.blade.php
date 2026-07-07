<section class="p-5 sm:p-8 max-w-[1080px]">
  @php
    // In 4-digit ("heading") mode the whole view reads as if we had collected 4-digit
    // codes: relabel the tabs (converge/diverge), truncate every displayed code.
    $tabs = $heading
        ? ['open' => __('Diverge'), 'agreed' => __('Converge'), 'confirmed' => __('Confirmed'), 'rejected' => __('Rejected'), 'no_match' => __('No match'), 'all' => __('All')]
        : ['open' => __('Needs attention'), 'ai_resolved' => __('AI resolved'), 'ai_proposed' => __('AI proposed'), 'agreed' => __('Agreed'), 'confirmed' => __('Confirmed'), 'rejected' => __('Rejected'), 'no_match' => __('No match'), 'all' => __('All')];
    $cd = fn ($c) => $heading && $c !== null && $c !== '' ? mb_substr((string) $c, 0, $digits) : $c;
    $kindBadge = fn ($k) => $k === 'service' ? 'bg-amber/15 text-amber' : ($k === 'good' ? 'bg-ledger/12 text-ledger' : 'bg-line/40 text-muted');
    $resBadge = fn ($s) => match ($s) {
        'agreed', 'confirmed' => 'bg-ledger/12 text-ledger',
        'ai_resolved' => 'bg-ink/10 text-ink',
        'review', 'blocked_on_fact' => 'bg-amber/15 text-amber',
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
      <label class="flex items-center gap-2 text-sm">
        <span class="text-muted">{{ __('Upload:') }}</span>
        <select wire:model.live="batch"
                class="px-3 py-1.5 rounded-lg text-sm border hair bg-surface focus:border-ink outline-none max-w-[280px]">
          <option value="all">{{ __('All uploads') }}</option>
          @foreach($batches as $b)
            <option value="{{ $b->key }}">{{ \Illuminate\Support\Str::limit($b->label, 32) }} · {{ $b->total }} items</option>
          @endforeach
        </select>
      </label>
      <a href="{{ route('review.export', ['batch' => $batch, 'filter' => $filter]) }}"
         class="btn btn-ghost btn-sm" title="{{ __('Export the current view (upload + status filter) to Excel') }}">⬇ {{ __('Export Excel') }}</a>
    </div>
  </div>

  {{-- Global code-detail mode: switches the WHOLE view (diagram, counts, tabs and
       every code) between the full 10-digit code and the 4-digit HS heading. Pure
       re-projection of the stored data — no re-classification, no LLM. --}}
  <div class="mb-5 flex items-center gap-3 flex-wrap">
    <span class="kicker">{{ __('Code detail') }}</span>
    <div class="inline-flex rounded-lg border hair overflow-hidden text-sm shadow-sm">
      <button wire:click="setCodeMode('full')"
              class="px-3.5 py-1.5 flex items-center gap-1.5 transition {{ ! $heading ? 'bg-ink text-paper' : 'bg-surface text-muted hover:text-ink' }}">
        {{ __('Full code') }} <span class="opacity-60 tnum text-xs">10</span>
      </button>
      <button wire:click="setCodeMode('heading')"
              class="px-3.5 py-1.5 flex items-center gap-1.5 transition border-l hair {{ $heading ? 'bg-ink text-paper' : 'bg-surface text-muted hover:text-ink' }}">
        {{ __('Heading') }} <span class="opacity-60 tnum text-xs">4</span>
      </button>
    </div>
    <span class="text-sm text-muted">
      {{ $heading
          ? __('Reading everything at the 4-digit HS heading — as if the codes were collected 4-digit.')
          : __('Reading the full 10-digit code.') }}
    </span>
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
              <span class="truncate">{{ $s['label'] }}</span>
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
            @foreach([[__('Agreed'),$cs['agreed'],'bg-ledger'],[__('AI resolved'),$cs['ai_resolved'] ?? 0,'bg-ink/60'],[__('AI proposed'),$cs['ai_proposed'] ?? 0,'bg-ink/40'],[__('Review'),$cs['review'],'bg-amber'],[__('Conflict'),$cs['conflict'],'bg-stamp']] as [$lbl,$val,$bar])
              <div class="flex items-center gap-2 text-sm">
                <span class="w-28 shrink-0 text-muted">{{ $lbl }}</span>
                <span class="flex-1 h-2 rounded-full bg-line/40 overflow-hidden"><span class="{{ $bar }} block h-full" style="width:{{ $val/$csTotal*100 }}%"></span></span>
                <span class="tnum text-faint w-8 text-right">{{ $val }}</span>
              </div>
            @endforeach
          </div>

          {{-- Mechanism convergence at the CURRENT granularity — recomputed from the
               stored per-mechanism codes (no LLM). At 4 digits it shows how many
               "conflicts" are just last-digit disagreements inside one heading. --}}
          <div class="mt-4 pt-3 border-t hair">
            <p class="kicker mb-1.5">{{ $heading ? __('Convergence at 4-digit heading') : __('Convergence at full code') }}</p>
            <div class="flex items-center gap-4 text-sm">
              <span class="text-ledger">✓ {{ __('converge') }} <span class="tnum font-medium">{{ $agreement['converge'] }}</span></span>
              <span class="text-stamp">✕ {{ __('diverge') }} <span class="tnum font-medium">{{ $agreement['diverge'] }}</span></span>
              @if($agreement['no_code'])<span class="text-faint">— {{ __('no code') }} <span class="tnum">{{ $agreement['no_code'] }}</span></span>@endif
              <span class="text-faint ml-auto">{{ __('of') }} {{ $agreement['total'] }}</span>
            </div>
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
        $editable = in_array($item->resolution, ['agreed','review','conflict','blocked_on_fact','confirmed','ai_resolved'], true);
        $allowed = $item->allowedCodes();
        $adj = $item->adjudications->sortByDesc('id')->first();
        // The AI-proposal framing belongs to the full-code view; the 4-digit view reads
        // the recomputed heading-level resolution (converge/diverge) instead.
        $aiProposed = ! $heading && $adj && $adj->verdict === 'resolved' && in_array($item->resolution, ['conflict','review'], true);
        // Pre-select the judge's answer when the item isn't final yet, so accepting it is one click.
        $default = (string) ($item->final_code ?? ($aiProposed ? $adj->winning_code : ($allowed[0] ?? '')));
        $vres = $heading ? ($vmap[$item->id] ?? $item->resolution) : $item->resolution;
        $badgeLabel = $aiProposed ? __('AI proposed') : str_replace('_',' ', $vres);
        $badgeClass = $aiProposed ? 'bg-ink/10 text-ink' : $resBadge($vres);
      @endphp
      <div wire:key="item-{{ $item->id }}" class="card-flat p-4 flex items-start gap-4 flex-wrap sm:flex-nowrap">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 mb-1 flex-wrap">
            <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $badgeClass }}">{{ $badgeLabel }}</span>
            <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $kindBadge($item->kind) }}">{{ $item->kind ?? '—' }}</span>
            <span class="font-mono text-sm">{{ $cd($item->final_code) ?? ($aiProposed ? $cd($adj->winning_code) : __('—')) }}</span>
            @if($batch === 'all' && $item->batch)
              <span class="px-2 py-0.5 rounded-md text-xs bg-line/40 text-muted">{{ \Illuminate\Support\Str::limit(optional($batchLabels->get($item->batch))->label ?? __('Earlier import'), 26) }}</span>
            @endif
          </div>
          <p class="font-medium">{{ $item->localizedSourceText() }}</p>
          @if($item->finalCode)
            <p class="text-muted text-sm mt-0.5">{{ Str::limit($item->finalCode->localizedName(), 110) }}</p>
          @endif

          {{-- Per-mechanism answers --}}
          <div class="flex flex-col gap-1 mt-2">
            @foreach($item->results as $res)
              @php $isFinal = $item->final_code && (string) $res->matched_code === (string) $item->final_code; @endphp
              <div class="flex items-start gap-2 text-xs">
                <span class="uppercase tracking-wide text-faint w-16 shrink-0">{{ $res->mechanism }}</span>
                <span class="font-mono shrink-0 {{ $isFinal ? 'text-ink font-medium' : 'text-muted' }}">{{ $cd($res->matched_code) ?? __('no match') }}</span>
                @if($res->matched_code && isset($catalogNames[(string) $res->matched_code]))
                  <span class="text-faint flex-1 min-w-0 break-words">· {{ \Illuminate\Support\Str::limit($catalogNames[(string) $res->matched_code], 140) }}</span>
                @endif
              </div>
            @endforeach
            @if($adj)
              <div class="flex items-start gap-2 text-xs mt-1">
                <span class="uppercase tracking-wide text-faint w-16 shrink-0">🤖 ai</span>
                @if($adj->verdict === 'resolved')
                  <span class="font-mono shrink-0 {{ $item->resolution === 'ai_resolved' ? 'text-ink font-medium' : 'text-muted' }}">{{ $cd($adj->winning_code) }}</span>
                  <span class="text-faint flex-1 min-w-0 break-words">·
                    @if($item->resolution === 'ai_resolved'){{ __('resolved by AI') }}@elseif(!$adj->stable){{ __('proposed — samples differed, your call') }}@elseif($adj->holdout){{ __('proposed — holdout, your call') }}@else{{ __('proposed') }}@endif
                    @if($adj->reason)· {{ \Illuminate\Support\Str::limit($adj->reason, 80) }}@endif
                  </span>
                @else
                  <span class="text-faint flex-1 min-w-0">· {{ __('could not decide — your call') }}</span>
                @endif
              </div>
            @endif

            {{-- Reference ("gold") labels — a hint for the reviewer only. NEVER shown
                 to the classifier/adjudicator. --}}
            @foreach($goldByItem[$item->id] ?? [] as $gl)
              @php
                $gShow = $gl->is_service ? __('service') : $cd($gl->code ?? $gl->heading);
                $gMatch = $gl->is_service
                    ? ($item->kind !== null ? (($item->kind === 'service') === (bool) $gl->is_service) : null)
                    : ($item->final_code ? (($gl->code && (string) $item->final_code === (string) $gl->code) || (! $gl->code && $gl->heading && mb_substr((string) $item->final_code, 0, 4) === $gl->heading)) : null);
                $disputed = data_get($gl->meta, 'crosscheck') === 'disagree';
              @endphp
              <div class="flex items-start gap-2 text-xs mt-1" title="{{ __('Reference label — never shown to the AI') }}">
                <span class="uppercase tracking-wide text-faint w-16 shrink-0">📋 {{ $gl->source }}</span>
                <span class="font-mono shrink-0 text-muted">{{ $gShow }}</span>
                @if($gMatch === true)<span class="text-ledger shrink-0">✓</span>@elseif($gMatch === false)<span class="text-stamp shrink-0">✕</span>@endif
                <span class="text-faint flex-1 min-w-0 break-words">
                  @if($gl->source === 'fedor' && $gl->tier)· {{ $gl->tier }}@endif
                  @if($disputed) · {{ __('disputed') }}@if(data_get($gl->meta, 'gpt_heading')) (gpt {{ data_get($gl->meta, 'gpt_heading') }})@endif @endif
                  @if($gl->category) · {{ \Illuminate\Support\Str::limit($gl->category, 54) }}@endif
                </span>
              </div>
            @endforeach
          </div>

          <a href="{{ route('review.decision', $item->id) }}" target="_blank"
             class="inline-block mt-2 text-xs text-muted hover:text-ink underline decoration-dotted">🔍 {{ __('Decision flow') }}</a>
        </div>

        <div class="shrink-0 w-full sm:w-[340px]">
          @if($editable && count($allowed) > 0)
            <div x-data="{ code: @js($default) }">
              <select x-model="code"
                      class="w-full px-2.5 py-1.5 rounded-lg text-xs border hair bg-surface focus:border-ink outline-none mb-2">
                @foreach($allowed as $c)
                  <option value="{{ $c }}">{{ $c }} · {{ \Illuminate\Support\Str::limit($catalogNames[$c] ?? '', 44) }}{{ (string) $c === (string) $item->final_code ? '  ← final' : '' }}</option>
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
            <span class="text-xs text-faint">{{ str_replace('_',' ',$item->resolution) }}</span>
          @endif
        </div>
      </div>
    @empty
      <div class="card-flat p-10 text-center text-muted">{{ __('Nothing here. Classify some items first.') }}</div>
    @endforelse
  </div>

  <div class="mt-5">{{ $items->onEachSide(1)->links() }}</div>
</section>
