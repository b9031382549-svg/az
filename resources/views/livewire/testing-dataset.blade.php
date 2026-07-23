<section class="p-5 sm:p-8 max-w-[1080px]">
  <div class="mb-5">
    <a href="{{ route('testing') }}" class="text-sm text-muted hover:underline">← {{ __('Testing') }}</a>
    <h1 class="font-display text-3xl mt-1">{{ $dataset->name }}</h1>
    <p class="text-sm text-muted mt-1">
      {{ __(':scorable of :total rows scorable', ['scorable' => $scorable, 'total' => $rows->total()]) }}
    </p>
  </div>

  {{-- Launch a run --}}
  <div class="card p-6 mb-6">
    <p class="font-medium mb-3">{{ __('New run') }}</p>
    <label class="field-label">{{ __('Description — what changed since last time?') }}</label>
    <input wire:model="description" class="field-input" placeholder="{{ __('e.g. baseline, or: heading-fusion on') }}">
    @error('description') <p class="text-sm text-stamp mt-1">{{ $message }}</p> @enderror
    <div class="mt-4 flex flex-wrap items-center gap-4">
      <span class="kicker">{{ __('Mechanisms') }}</span>
      @foreach([['useVector', __('Vector')], ['useBroker', __('Broker')], ['useDirect', __('Direct')], ['useSearch', __('Web search')], ['useMemory', __('Memory')]] as [$prop, $label])
        <label class="flex items-center gap-1.5 text-sm">
          {{-- Memory is .live so ticking it reveals the memory panel below --}}
          <input type="checkbox" wire:model{{ $prop === 'useMemory' ? '.live' : '' }}="{{ $prop }}"> {{ $label }}
        </label>
      @endforeach
      <button wire:click="launch" wire:loading.attr="disabled" wire:target="launch" class="btn btn-ink btn-sm ml-auto">
        <span wire:loading.remove wire:target="launch">{{ __('Run dataset →') }}</span>
        <span wire:loading wire:target="launch">{{ __('Starting…') }}</span>
      </button>
    </div>
    {{-- Optional: point THIS run at an external model endpoint (e.g. a fine-tuned model
         on a rented GPU). Blank → the run mirrors prod. Only the decision stages are
         routed there; web search stays on prod. --}}
    <div x-data="{ open: @js($endpointModel !== '') }" class="mt-4 pt-4 border-t hair">
      <button type="button" x-on:click="open = !open" class="text-sm text-muted hover:underline">
        <span x-text="open ? '▾' : '▸'"></span> {{ __('Test an external model (rented GPU)') }}
      </button>
      <div x-show="open" x-cloak class="mt-3 grid gap-3 sm:grid-cols-2">
        <div>
          <label class="field-label">{{ __('Decision model') }}</label>
          <input wire:model="endpointModel" class="field-input" placeholder="xif">
          @error('endpointModel') <p class="text-sm text-stamp mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
          <label class="field-label">{{ __('Expand model (optional)') }}</label>
          <input wire:model="endpointExpandModel" class="field-input" placeholder="base">
          @error('endpointExpandModel') <p class="text-sm text-stamp mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
          <label class="field-label">{{ __('Endpoint URL') }}</label>
          <input wire:model="endpointBaseUrl" class="field-input" placeholder="http://<ip>:8000/v1">
          @error('endpointBaseUrl') <p class="text-sm text-stamp mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
          <label class="field-label">{{ __('API key') }}</label>
          <input wire:model="endpointKey" class="field-input" placeholder="sk-vmtest">
        </div>
      </div>
      <p x-show="open" x-cloak class="text-xs text-faint mt-2">{{ __('Routes the decision stages (rerank, broker, direct) at this endpoint and votes at 4-digit heading. Expand goes there too only if you set an expand model (a fine-tuned decision model can\'t expand — use base). Web search always stays on prod. Leave all blank for a normal prod-mirroring run.') }}</p>
    </div>
    <p class="text-xs text-faint mt-3">{{ __('The effective models + retrieval flags are snapshotted at launch, so a later comparison reflects the code change, not config drift.') }}</p>
  </div>

  {{-- Dataset memory — shown only when the Memory mechanism is ticked for a run --}}
  @if($useMemory)
  <div class="card p-6 mb-6">
    <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
      <p class="font-medium">{{ __('Memory') }} <span class="text-muted font-normal">· {{ $memoryCount }} {{ __('entries') }}</span></p>
      @if($memoryCount > 0)
        <button wire:click="clearMemory" class="btn btn-ghost btn-sm">{{ __('Clear memory') }}</button>
      @endif
    </div>
    <p class="text-sm text-muted mb-3 max-w-[72ch]">{{ __('Memory is bound to THIS dataset only — production never sees it. Tick the Memory mechanism on a run to use it; a hit short-circuits the pipeline, exactly like production.') }}</p>
    <div class="flex flex-wrap items-center gap-2">
      <button wire:click="seedMemoryFromLabels" wire:loading.attr="disabled" wire:target="seedMemoryFromLabels" class="btn btn-ghost btn-sm">{{ __('Seed from correct answers') }}</button>
      @if($doneRuns->isNotEmpty())
        <span class="text-muted text-sm ml-1">{{ __('or from a run:') }}</span>
        <select wire:model="seedRunId" class="field-input py-1 h-9 w-auto">
          <option value="">{{ __('choose a run…') }}</option>
          @foreach($doneRuns as $r)<option value="{{ $r->id }}">#{{ $r->id }} · {{ Str::limit($r->description, 22) }}</option>@endforeach
        </select>
        <button wire:click="seedMemoryFromRun" class="btn btn-ghost btn-sm">{{ __('Seed') }}</button>
      @endif
    </div>
    <p class="text-xs text-faint mt-3">{{ __('“Correct answers” is the perfect-memory ceiling (leakage — exact-name rows then score ~100%). “From a run” replays what the pipeline produced (the flywheel).') }}</p>
  </div>
  @endif

  {{-- Accuracy-by-run chart (interactive: hover to highlight + tooltip; mechanism checkboxes toggle lines) --}}
  @if(($chart['count'] ?? 0) >= 1)
    @php
      $labels = $chart['labels']; $series = $chart['series']; $n = count($labels);
      $W = 640; $H = 250; $pl = 34; $pr = 14; $pt = 12; $pb = 28;
      $plotW = $W - $pl - $pr; $plotH = $H - $pt - $pb;
      $xAt = fn ($i) => $n <= 1 ? $pl + $plotW / 2 : $pl + $i / max(1, $n - 1) * $plotW;
      $yAt = fn ($a) => $pt + (1 - $a / 100) * $plotH;
      $colors = ['overall' => 'currentColor', 'majority' => '#3f6b4f', 'vector' => '#2563eb', 'broker' => '#7c3aed', 'direct' => '#0891b2', 'search' => '#B5462E', 'memory' => '#9a9183'];
      $names = ['overall' => __('Overall'), 'majority' => __('Majority'), 'vector' => __('Vector'), 'broker' => __('Broker'), 'direct' => __('Direct'), 'search' => __('Web search'), 'memory' => __('Memory')];
      // Which mechanism checkbox toggles each line (overall/majority are composites — always on).
      $vis = ['overall' => 'true', 'majority' => 'true', 'vector' => '$wire.useVector', 'broker' => '$wire.useBroker', 'direct' => '$wire.useDirect', 'search' => '$wire.useSearch', 'memory' => '$wire.useMemory'];
    @endphp
    <div class="card p-5 mb-6" x-data="{ hover: null, tip: { show: false, text: '', x: 0, y: 0 } }">
      <p class="font-medium mb-3">{{ __('Accuracy by run') }}</p>
      <div class="overflow-x-auto">
        <svg viewBox="0 0 {{ $W }} {{ $H }}" class="w-full min-w-[440px]" style="max-height:270px">
          @foreach([0, 25, 50, 75, 100] as $g)
            <line x1="{{ $pl }}" y1="{{ $yAt($g) }}" x2="{{ $W - $pr }}" y2="{{ $yAt($g) }}" stroke="currentColor" stroke-opacity="0.12"/>
            <text x="{{ $pl - 6 }}" y="{{ $yAt($g) + 3 }}" text-anchor="end" font-size="10" fill="currentColor" fill-opacity="0.5">{{ $g }}</text>
          @endforeach
          @foreach($labels as $i => $lab)
            <text x="{{ $xAt($i) }}" y="{{ $H - 9 }}" text-anchor="middle" font-size="10" fill="currentColor" fill-opacity="0.5">{{ $lab }}</text>
          @endforeach
          @foreach($series as $key => $pts)
            @php
              $pointStr = collect($pts)->map(fn ($a, $i) => $a === null ? null : $xAt($i).','.$yAt($a))->filter()->implode(' ');
              $w = $key === 'overall' ? 2.5 : 1.5; $r = $key === 'overall' ? 3 : 2.5;
            @endphp
            @if($pointStr !== '')
              <g x-show="{{ $vis[$key] }}"
                 x-on:mouseenter="hover = '{{ $key }}'" x-on:mouseleave="hover = null"
                 x-bind:opacity="hover === null || hover === '{{ $key }}' ? 1 : 0.15" style="transition:opacity .12s">
                {{-- wide transparent hit-line so the thin stroke is easy to hover --}}
                <polyline points="{{ $pointStr }}" fill="none" stroke="transparent" stroke-width="12" style="cursor:pointer"/>
                <polyline points="{{ $pointStr }}" fill="none" stroke="{{ $colors[$key] }}" stroke-linejoin="round" stroke-linecap="round"
                          x-bind:stroke-width="hover === '{{ $key }}' ? {{ $w + 1.4 }} : {{ $w }}" style="transition:stroke-width .1s"/>
                @foreach($pts as $i => $a)
                  @if($a !== null)
                    <circle cx="{{ $xAt($i) }}" cy="{{ $yAt($a) }}" fill="{{ $colors[$key] }}"
                            x-bind:r="hover === '{{ $key }}' ? {{ $r + 1 }} : {{ $r }}"/>
                    <circle cx="{{ $xAt($i) }}" cy="{{ $yAt($a) }}" r="11" fill="transparent" style="cursor:pointer"
                            data-tip="{{ $names[$key] }} · {{ $labels[$i] }}: {{ $a }}%"
                            x-on:mouseenter="hover = '{{ $key }}'; tip = { show: true, text: $el.dataset.tip, x: $event.clientX + 12, y: $event.clientY - 12 }"
                            x-on:mousemove="tip.x = $event.clientX + 12; tip.y = $event.clientY - 12"
                            x-on:mouseleave="hover = null; tip.show = false"/>
                  @endif
                @endforeach
              </g>
            @endif
          @endforeach
        </svg>
      </div>
      {{-- legend: hover to highlight, dimmed when its checkbox hides the line --}}
      <div class="flex flex-wrap gap-x-4 gap-y-1 mt-3 text-xs text-muted">
        @foreach($series as $key => $pts)
          <span class="inline-flex items-center gap-1.5" style="cursor:default"
                x-bind:class="({{ $vis[$key] }}) ? '' : 'opacity-30'"
                x-on:mouseenter="hover = '{{ $key }}'" x-on:mouseleave="hover = null">
            <span class="w-3 h-[3px] rounded-full" style="background:{{ $colors[$key] }}"></span>{{ $names[$key] }}
          </span>
        @endforeach
      </div>
      {{-- cursor-following tooltip --}}
      <div x-cloak x-show="tip.show" x-text="tip.text" x-bind:style="`left:${tip.x}px; top:${tip.y}px`"
           class="fixed z-50 pointer-events-none px-2 py-1 rounded-md bg-ink text-paper text-xs font-medium shadow-lg"></div>
    </div>
  @endif

  {{-- Runs --}}
  <div class="flex items-center justify-between mb-2">
    <p class="font-medium">{{ __('Runs') }}</p>
    @if($runs->count() >= 2)
      <form method="GET" action="{{ route('testing.compare') }}" class="flex items-center gap-2 text-sm">
        <select name="a" class="field-input py-1 h-9 w-auto">@foreach($runs as $r)<option value="{{ $r->id }}">#{{ $r->id }} · {{ Str::limit($r->description, 22) }}</option>@endforeach</select>
        <span class="text-muted">{{ __('vs') }}</span>
        <select name="b" class="field-input py-1 h-9 w-auto">@foreach($runs as $r)<option value="{{ $r->id }}">#{{ $r->id }} · {{ Str::limit($r->description, 22) }}</option>@endforeach</select>
        <button class="btn btn-ghost btn-sm">{{ __('Compare') }}</button>
      </form>
    @endif
  </div>
  <div class="card p-0 overflow-hidden mb-6">
    <table class="w-full text-sm">
      <thead class="text-muted text-left">
        <tr class="border-b hair">
          <th class="px-4 py-3 font-medium">{{ __('Run') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Overall') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Duration') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Tokens') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('When') }}</th>
        </tr>
      </thead>
      <tbody>
        @forelse($runs as $r)
          @php
            $o = $r->accuracy['columns']['overall'] ?? null;
            $acc = ($o && ($o['ran'] ?? 0) > 0) ? round(100 * $o['correct'] / $o['ran']) : null;
            $tok = $r->accuracy['tokens'] ?? null;
            $durS = ($r->started_at && $r->finished_at) ? (int) abs($r->finished_at->diffInSeconds($r->started_at)) : null;
            $dur = $durS === null ? '—' : (intdiv($durS, 60) > 0 ? intdiv($durS, 60).'m '.($durS % 60).'s' : $durS.'s');
          @endphp
          <tr class="border-b hair hover:bg-surface">
            <td class="px-4 py-3"><a href="{{ route('testing.run', $r) }}" class="hover:underline">#{{ $r->id }} · {{ $r->description }}</a>@if($r->model_override)<span class="ml-1.5 text-xs px-1.5 py-0.5 rounded bg-line/40 text-muted font-mono" title="{{ __('External endpoint') }}">{{ $r->model_override }}</span>@endif</td>
            <td class="px-4 py-3"><span class="text-xs px-2 py-0.5 rounded-full {{ $r->status === 'done' ? 'bg-ledger/12 text-ledger' : 'bg-line/40 text-muted' }}">{{ __(ucfirst($r->status)) }}</span></td>
            <td class="px-4 py-3 tnum font-medium">{{ $acc !== null ? $acc.'%' : '—' }}</td>
            <td class="px-4 py-3 tnum text-muted">{{ $dur }}</td>
            <td class="px-4 py-3 tnum text-muted">{{ $tok !== null ? number_format($tok, 0, '.', ' ') : '—' }}</td>
            <td class="px-4 py-3 text-muted">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="px-4 py-6 text-center text-muted">{{ __('No runs yet — launch one above.') }}</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- Rows --}}
  <p class="font-medium mb-2">{{ __('Rows') }}</p>
  <div class="card p-0 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="text-muted text-left">
        <tr class="border-b hair">
          <th class="px-4 py-3 font-medium">{{ __('Item') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Expected code') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Heading') }}</th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $row)
          <tr class="border-b hair {{ $row->skip_reason ? 'opacity-50' : '' }}">
            <td class="px-4 py-3">{{ $row->source_text }}</td>
            <td class="px-4 py-3 font-mono">{{ $row->expected_code ?? '—' }}</td>
            <td class="px-4 py-3">
              @if($row->skip_reason)
                <span class="text-xs text-stamp" title="{{ $row->skip_reason }}">{{ __('skipped') }}</span>
              @else
                <span class="font-mono">{{ $row->expected_is_service ? 'SVC' : $row->expected_heading }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <div class="mt-4">{{ $rows->onEachSide(1)->links() }}</div>
</section>
