<section class="p-5 sm:p-8 max-w-[1000px]">
  @php
    $nm = fn ($c) => $names[(string) $c] ?? '';
    // Localized rubricator title by code, falling back to the title stored in the
    // trace (older traces recorded the Azerbaijani title inline).
    $rt = fn ($c, $fallback = '') => $rubricTitles[(string) $c] ?? ($fallback ?? '');
    // The display translation of the classified text, shown as an aid under the
    // trace's "Input" line (which stays the literal, original-language string the
    // classifier actually processed). Null in az mode or when no translation exists.
    $srcTranslation = app()->getLocale() !== 'az' ? $item->localizedSourceText() : null;
    $srcTranslation = ($srcTranslation !== null && $srcTranslation !== $item->source_text) ? $srcTranslation : null;
    $statusBadge = fn ($s) => match ($s) {
        'auto_confirmed', 'agreed', 'confirmed' => 'bg-ledger/12 text-ledger',
        'needs_review', 'review', 'blocked_on_fact' => 'bg-amber/15 text-amber',
        'conflict', 'error' => 'bg-stamp/12 text-stamp',
        default => 'bg-line/40 text-muted',
    };
    $pct = fn ($v) => $v !== null ? number_format((float) $v * 100, 0).'%' : '—';
    // vector first, then broker, then the rest
    $order = ['vector' => 0, 'broker' => 1];
    $results = $item->results->sortBy(fn ($r) => $order[$r->mechanism] ?? 9)->values();
  @endphp

  <div class="mb-5">
    <a href="{{ route('review', ['batch' => $item->batch, 'filter' => 'all']) }}" class="text-sm text-muted hover:text-ink">← {{ __('Back to review') }}</a>
    <p class="kicker mt-2 mb-1">{{ __('Decision flow') }}</p>
    <h1 class="font-display text-3xl">{{ $item->localizedSourceText() }}</h1>
    <div class="flex items-center gap-2 mt-2 flex-wrap">
      <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $statusBadge($item->resolution) }}">{{ str_replace('_', ' ', $item->resolution) }}</span>
      @if($item->final_code)
        <span class="font-mono text-sm">{{ $item->final_code }}</span>
        <span class="text-muted text-sm">{{ \Illuminate\Support\Str::limit($item->finalCode?->localizedName() ?? '', 80) }}</span>
      @endif
    </div>
  </div>

  <div class="space-y-5">
    @foreach($results as $res)
      @php $t = $res->trace; @endphp
      <div class="card p-5">
        <div class="flex items-center justify-between gap-3 flex-wrap mb-4 pb-3 border-b hair">
          <div class="flex items-center gap-2">
            <span class="kicker">{{ $res->mechanism }}</span>
            @if($res->model)<span class="text-faint text-xs">{{ $res->model }}</span>@endif
          </div>
          <div class="flex items-center gap-2">
            <span class="font-mono text-sm">{{ $res->matched_code ?? __('no match') }}</span>
            <span class="text-faint text-xs tnum">{{ $pct($res->confidence) }}</span>
            <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $statusBadge($res->status) }}">{{ str_replace('_', ' ', $res->status) }}</span>
          </div>
        </div>

        @if(!$t)
          {{-- Pre-trace item: light view from stored data. --}}
          <p class="text-muted text-sm mb-2">{{ __('Detailed trace was not captured for this item (classified before the decision-flow feature). Showing what was stored:') }}</p>
          @if($res->explanation)<p class="text-sm mb-2"><span class="text-faint">{{ __('Reason') }}:</span> {{ $res->explanation }}</p>@endif
          @if($res->path)
            <p class="kicker mb-1">{{ __('Descent path') }}</p>
            <div class="flex flex-wrap gap-1 text-xs font-mono">
              @foreach($res->path as $p)<span class="px-1.5 py-0.5 rounded bg-paper/60">{{ $p['code'] ?? ($p['by'] ?? '?') }}</span>@endforeach
            </div>
          @endif
          @if($res->candidates)
            <p class="kicker mt-3 mb-1">{{ __('Candidates considered') }}</p>
            <div class="text-xs space-y-0.5 max-h-64 overflow-auto">
              @foreach(array_slice($res->candidates, 0, 24) as $c)
                <div class="{{ (string)($c['code'] ?? '') === (string)$res->matched_code ? 'font-medium text-ink' : 'text-muted' }}">
                  <span class="font-mono">{{ $c['code'] ?? '' }}</span> · {{ $nm($c['code'] ?? '') ?: ($c['name'] ?? '') }}
                </div>
              @endforeach
            </div>
          @endif

        @elseif(($t['steps'] ?? null) !== null)
          {{-- BROKER: descent trace --}}
          <div class="space-y-3 text-sm">
            <div><span class="text-faint">{{ __('Input') }}:</span> {{ $t['input'] ?? $item->source_text }}
              @if($srcTranslation)<div class="text-muted mt-0.5">↳ {{ __('Translation') }}: <em>{{ $srcTranslation }}</em></div>@endif
              @if(!empty($t['essence']))<div class="text-muted mt-0.5">↳ {{ __('Normalized') }}: <em>{{ $t['essence'] }}</em></div>@endif
            </div>

            @if(!empty($t['brief']))
              @php $b = $t['brief']; @endphp
              <div class="rounded-lg border hair p-3">
                <span class="kicker">{{ __('Product brief') }}</span>
                <p class="text-ink mt-1">{{ $b['identity'] ?? '' }}</p>
                @if(!empty($b['purpose']))<p class="text-muted text-xs mt-0.5">{{ __('Purpose') }}: {{ $b['purpose'] }}</p>@endif
                <div class="text-xs text-faint mt-1 flex flex-wrap gap-x-3 gap-y-0.5">
                  <span>{{ __('Type') }}: {{ $b['function_class'] ?? '' }}</span>
                  @if(!empty($b['material']['value']))<span>{{ __('Material') }}: {{ $b['material']['value'] }} ({{ $b['material']['basis'] ?? 'unknown' }})</span>@endif
                  <span>{{ __('Decides') }}: {{ $b['decisive_axis'] ?? '' }}</span>
                  <span>{{ $pct($b['confidence'] ?? null) }}</span>
                </div>
              </div>
            @endif

            @foreach($t['steps'] as $i => $s)
              @php $type = $s['type'] ?? ''; @endphp
              <div class="rounded-lg border hair p-3">
                @if($type === 'fork')
                  <div class="flex items-center justify-between gap-2 mb-1">
                    <span class="kicker">{{ __('Fork') }} {{ $i + 1 }}{{ !empty($s['after_fact']) ? ' · '.__('after fact') : '' }}</span>
                    <span class="text-xs {{ $s['accepted'] ? 'text-ledger' : 'text-stamp' }}">{{ $s['accepted'] ? '✓ '.__('decided') : '✕ '.__('undecided') }} · {{ $pct($s['confidence'] ?? null) }}</span>
                  </div>
                  @if(!empty($s['criterion']))<p class="text-muted mb-2">{{ __('Criterion') }}: {{ $s['criterion'] }}</p>@endif
                  <div class="space-y-1">
                    @foreach(($s['options'] ?? []) as $o)
                      @php $chosen = (string)($o['code'] ?? '') === (string)($s['chosen'] ?? ''); @endphp
                      <div class="text-xs {{ $chosen ? 'text-ink' : 'text-muted' }}">
                        <span class="font-mono">{{ $chosen ? '→ ' : '  ' }}{{ $o['code'] ?? '' }}</span>
                        <span class="{{ $chosen ? 'font-medium' : '' }}">{{ $rt($o['code'] ?? '', $o['title'] ?? '') }}</span>
                        @if(!empty($o['samples']))<span class="text-faint"> — {{ \Illuminate\Support\Str::limit($o['samples'], 90) }}</span>@endif
                      </div>
                    @endforeach
                  </div>
                  @if(!empty($s['question']))<p class="text-amber text-xs mt-2">{{ __('Missing fact') }}: {{ $s['question'] }}</p>@endif
                @elseif($type === 'auto')
                  <span class="text-muted"><span class="kicker">{{ __('Only child') }}</span> — <span class="font-mono">{{ $s['code'] ?? '' }}</span> {{ $rt($s['code'] ?? '', $s['title'] ?? '') }}</span>
                @elseif($type === 'fact')
                  <div><span class="kicker">{{ __('Fact lookup') }}</span>
                    <p class="text-muted mt-1">{{ __('Asked') }}: {{ $s['question'] ?? '' }}</p>
                    <p class="{{ !empty($s['fact']) ? 'text-ledger' : 'text-stamp' }}">{{ !empty($s['fact']) ? '→ '.$s['fact'] : '→ '.__('not determinable') }}</p>
                  </div>
                @elseif($type === 'leaf')
                  <div class="flex items-center justify-between gap-2 mb-1">
                    <span class="kicker">{{ __('Final code') }}</span>
                    <span class="text-xs text-faint">{{ $pct($s['confidence'] ?? null) }}</span>
                  </div>
                  <div class="space-y-0.5 max-h-52 overflow-auto">
                    @foreach(($s['options'] ?? []) as $o)
                      @php $chosen = (string)($o['code'] ?? '') === (string)($s['chosen'] ?? ''); @endphp
                      <div class="text-xs {{ $chosen ? 'font-medium text-ink' : 'text-muted' }}"><span class="font-mono">{{ $chosen ? '→ ' : '  ' }}{{ $o['code'] ?? '' }}</span> {{ $nm($o['code'] ?? '') ?: ($o['name'] ?? '') }}</div>
                    @endforeach
                  </div>
                  @if(!empty($s['reason']))<p class="text-faint text-xs mt-1">{{ $s['reason'] }}</p>@endif
                @elseif($type === 'fallback')
                  <div><span class="kicker text-stamp">{{ __('Fallback (retrieval)') }}</span>
                    @if(!empty($s['reason']))<span class="text-faint text-xs"> — {{ $s['reason'] }}</span>@endif
                    <div class="space-y-0.5 max-h-52 overflow-auto mt-1">
                      @foreach(($s['options'] ?? []) as $o)
                        @php $chosen = (string)($o['code'] ?? '') === (string)($s['chosen'] ?? ''); @endphp
                        <div class="text-xs {{ $chosen ? 'font-medium text-ink' : 'text-muted' }}"><span class="font-mono">{{ $chosen ? '→ ' : '  ' }}{{ $o['code'] ?? '' }}</span> {{ $nm($o['code'] ?? '') ?: ($o['name'] ?? '') }}</div>
                      @endforeach
                    </div>
                  </div>
                @endif
              </div>
            @endforeach

            @includeWhen(!empty($t['gate']), 'livewire.partials.decision-gate', ['gate' => $t['gate']])
          </div>

        @else
          {{-- VECTOR: retrieval + rerank trace --}}
          <div class="space-y-3 text-sm">
            <div><span class="text-faint">{{ __('Input') }}:</span> {{ $t['input'] ?? $item->source_text }}
              @if($srcTranslation)<div class="text-muted mt-0.5">↳ {{ __('Translation') }}: <em>{{ $srcTranslation }}</em></div>@endif
            </div>
            @if(!empty($t['queries']))
              <div class="rounded-lg border hair p-3">
                <span class="kicker">{{ __('Search queries') }}</span>
                <ul class="mt-1 text-xs text-muted list-disc pl-4">
                  @foreach($t['queries'] as $qy)<li>{{ $qy }}</li>@endforeach
                </ul>
              </div>
            @endif
            <div class="rounded-lg border hair p-3">
              <span class="kicker">{{ __('Candidates') }} ({{ count($t['candidates'] ?? []) }}) — {{ __('the shortlist the model chose from') }}</span>
              <div class="mt-2 text-xs space-y-0.5 max-h-72 overflow-auto">
                <div class="flex items-start gap-2 text-faint">
                  <span class="w-28 shrink-0">{{ __('Code') }}</span><span class="flex-1">{{ __('Name') }}</span><span class="w-12 shrink-0 text-right">score</span><span class="w-14 shrink-0 text-right">cosine</span>
                </div>
                @foreach(($t['candidates'] ?? []) as $c)
                  @php $chosen = (string)($c['code'] ?? '') === (string)$res->matched_code; @endphp
                  <div class="flex items-start gap-2 {{ $chosen ? 'font-medium text-ink' : 'text-muted' }}">
                    <span class="w-28 shrink-0 font-mono">{{ $chosen ? '→ ' : '' }}{{ $c['code'] ?? '' }}</span>
                    <span class="flex-1 break-words">{{ $nm($c['code'] ?? '') ?: ($c['name'] ?? '') }}</span>
                    <span class="w-12 shrink-0 text-right tnum">{{ isset($c['score']) ? number_format((float)$c['score'], 3) : '' }}</span>
                    <span class="w-14 shrink-0 text-right tnum">{{ isset($c['semantic_sim']) && $c['semantic_sim'] !== null ? number_format((float)$c['semantic_sim'], 2) : '—' }}</span>
                  </div>
                @endforeach
              </div>
            </div>
            @if(!empty($t['rerank']))
              @php $rr = $t['rerank']; @endphp
              <div class="rounded-lg border hair p-3">
                <span class="kicker">{{ __('Re-rank') }} — {{ __('tier') }} {{ $rr['tier'] ?? '?' }}{{ !empty($rr['escalated']) ? ' · '.__('escalated to fallback model') : '' }}</span>
                <p class="mt-1"><span class="font-mono">{{ $rr['code'] ?? '—' }}</span> · {{ $pct($rr['confidence'] ?? null) }} <span class="text-faint">({{ $rr['model'] ?? '' }})</span></p>
                @if(!empty($rr['reason']))<p class="text-faint text-xs mt-0.5">{{ $rr['reason'] }}</p>@endif
              </div>
            @endif
            @includeWhen(!empty($t['gate']), 'livewire.partials.decision-gate', ['gate' => $t['gate']])
          </div>
        @endif
      </div>
    @endforeach
  </div>

  <div class="card-flat p-4 mt-5 text-sm">
    <span class="kicker">{{ __('Consensus') }}</span>
    <p class="mt-1 text-muted">{{ __('Result') }}:
      <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $statusBadge($item->resolution) }}">{{ str_replace('_', ' ', $item->resolution) }}</span>
      @if($item->resolution === 'conflict') — {{ __('mechanisms disagreed → sent to a human') }}
      @elseif($item->resolution === 'agreed') — {{ __('all mechanisms agreed → auto-confirmed') }}
      @elseif($item->resolution === 'ai_resolved') — {{ __('mechanisms diverged → AI adjudicator resolved it') }}
      @endif
    </p>
  </div>

  @php $adj = $item->adjudications->sortByDesc('id')->first(); @endphp
  @if($adj)
    <div class="card-flat p-4 mt-3 text-sm">
      <span class="kicker">{{ __('AI adjudicator') }} <span class="text-faint">({{ $adj->model }} · {{ $adj->mode }})</span></span>
      <p class="mt-1">
        <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $adj->verdict === 'resolved' ? 'bg-ledger/12 text-ledger' : 'bg-line/40 text-muted' }}">{{ $adj->verdict }}</span>
        @if($adj->winning_code)<span class="font-mono ml-1">{{ $adj->winning_code }}</span> <span class="text-faint">({{ $adj->which_mechanism }}, {{ $pct($adj->confidence) }})</span>@endif
        @if(!$adj->stable)<span class="text-stamp text-xs ml-1">· {{ __('unstable → human') }}</span>@endif
        @if($adj->holdout)<span class="text-amber text-xs ml-1">· {{ __('holdout → human') }}</span>@endif
        @if($adj->applied)<span class="text-ledger text-xs ml-1">· {{ __('applied → ai_resolved') }}</span>@endif
      </p>
      @if($adj->winning_code && $nm($adj->winning_code))<p class="text-muted text-xs mt-0.5">{{ $nm($adj->winning_code) }}</p>@endif
      @if(!empty($adj->samples))
        <p class="text-faint text-xs mt-1">{{ __('Stability samples') }}:
          @foreach($adj->samples as $s)<span class="font-mono">{{ $s['code'] ?? ($s['verdict'] ?? '∅') }}</span>@if(!$loop->last), @endif @endforeach
          @unless($adj->stable) → <span class="text-stamp">{{ __('differed → kept with a human') }}</span>@endunless
        </p>
      @endif
      @if($adj->rule_basis)<p class="text-muted text-xs mt-1">{{ __('Basis') }}: {{ $adj->rule_basis }}</p>@endif
      @if($adj->reason)<p class="text-faint text-xs mt-0.5">{{ $adj->reason }}</p>@endif
    </div>
  @endif
</section>
