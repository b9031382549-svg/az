<section class="p-5 sm:p-8 max-w-[1000px]">
  @php
    $nm = fn ($c) => $names[(string) $c] ?? '';
    // Localized rubricator title by code, falling back to the title stored in the trace.
    $rt = fn ($c, $fallback = '') => $rubricTitles[(string) $c] ?? ($fallback ?? '');
    // Any code's name — catalog (10-digit) or rubricator heading (4-digit).
    $anyName = fn ($c) => $names[(string) $c] ?? ($rubricTitles[(string) $c] ?? '');
    $srcTranslation = app()->getLocale() !== 'az' ? $item->localizedSourceText() : null;
    $srcTranslation = ($srcTranslation !== null && $srcTranslation !== $item->source_text) ? $srcTranslation : null;
    $pct = fn ($v) => $v !== null ? number_format((float) $v * 100, 0).'%' : '—';
    // Localized resolution / mechanism labels (flat JSON translations — no dotted keys).
    $statusLabel = fn ($r) => match ($r) {
        'agreed' => __('agreed'),
        'conflict' => __('conflict'),
        'ai_resolved' => __('resolved by AI'),
        'no_match' => __('no match'),
        'confirmed' => __('confirmed'),
        'rejected' => __('rejected'),
        'review' => __('needs review'),
        'pending' => __('pending'),
        'blocked_on_fact' => __('blocked on fact'),
        default => str_replace('_', ' ', (string) $r),
    };
    $mechLabel = fn ($m) => match ($m) {
        'vector' => __('Vector'),
        'broker' => __('Broker'),
        'direct' => __('Direct recall'),
        default => $m,
    };
    // Stage status pill colouring.
    $pill = fn ($tone) => match ($tone) {
        'good' => 'bg-ledger/12 text-ledger',
        'warn' => 'bg-amber/15 text-amber',
        'bad' => 'bg-stamp/12 text-stamp',
        default => 'bg-line/40 text-muted',
    };
    $isHeading = (($fl = mb_strlen((string) $item->final_code)) > 0 && $fl < 10);
    $finalName = $item->finalCode?->localizedName() ?: $rt($item->final_code);

    $isCacheHit = $cache !== null;
    $aiRan = $mechResults->isNotEmpty();
    $searchRan = $search !== null;
    $searchResolved = $searchRan && $search->status === 'auto_confirmed';
    $humanDecided = in_array($item->resolution, ['confirmed', 'rejected'], true);
  @endphp

  <div class="mb-6">
    <a href="{{ route('review', ['batch' => $item->batch, 'filter' => 'all']) }}" class="text-sm text-muted hover:text-ink">← {{ __('Back to review') }}</a>
    <p class="kicker mt-3 mb-1">{{ __('Decision flow') }}</p>
    <h1 class="font-display text-3xl">{{ $item->localizedSourceText() }}</h1>

    {{-- The overall outcome — what came out of the whole flow. --}}
    <div class="card-flat p-3 mt-3 flex items-center gap-2 flex-wrap text-sm">
      <span class="kicker">{{ __('Final answer') }}</span>
      <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $pill($humanDecided ? ($item->resolution === 'rejected' ? 'bad' : 'good') : ($item->resolution === 'conflict' ? 'warn' : ($item->final_code ? 'good' : 'muted'))) }}">{{ $statusLabel($item->resolution) }}</span>
      @if($item->final_code)
        <span class="font-mono text-sm">{{ $item->final_code }}</span>
        <span class="text-muted">{{ \Illuminate\Support\Str::limit($finalName, 90) }}</span>
        @if($isHeading)<span class="px-1.5 py-0.5 rounded text-[10px] bg-line/40 text-muted">{{ (string) $item->final_code === '99' ? __('service level') : __('heading only') }}</span>@endif
      @else
        <span class="text-muted">{{ __('awaiting a human decision') }}</span>
      @endif
    </div>
  </div>

  {{-- The stages of the flow: cache → AI consensus → web search → human. Each shows
       its input → output up front; the deep trace is collapsible. --}}
  <ol class="space-y-3">

    {{-- ① CACHE --}}
    <li class="card p-5">
      <div class="flex items-center justify-between gap-3 mb-3">
        <div class="flex items-center gap-2.5">
          <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-line/40 text-xs font-semibold">1</span>
          <span class="font-medium">{{ __('Cache') }}</span>
          <span class="text-faint text-xs">{{ __('exact-name lookup') }}</span>
        </div>
        <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $pill($isCacheHit ? 'good' : 'muted') }}">{{ $isCacheHit ? __('hit') : __('miss') }}</span>
      </div>
      <div class="flex flex-col sm:flex-row gap-2 text-sm">
        <div class="flex-1 rounded-lg border hair p-3 min-w-0">
          <p class="kicker mb-1">{{ __('Input') }}</p>
          <p class="break-words">{{ $item->localizedSourceText() }}</p>
        </div>
        <div class="flex items-center justify-center text-faint">→</div>
        <div class="flex-1 rounded-lg border hair p-3 min-w-0">
          <p class="kicker mb-1">{{ __('Output') }}</p>
          @if($isCacheHit)
            <p><span class="font-mono">{{ $cache->matched_code }}</span> <span class="text-muted">{{ \Illuminate\Support\Str::limit($anyName($cache->matched_code), 60) }}</span></p>
            <p class="text-ledger text-xs mt-0.5">{{ __('found — answered from the cache, no AI needed') }}</p>
          @else
            <p class="text-muted">{{ __('not found → passed to the AI') }}</p>
          @endif
        </div>
      </div>
      @if($isCacheHit)
        <details class="mt-3 group">
          <summary class="cursor-pointer text-xs text-muted hover:text-ink select-none list-none [&::-webkit-details-marker]:hidden flex items-center gap-1">
            <span class="transition-transform group-open:rotate-90">▸</span> {{ __('Details') }}
          </summary>
          <div class="mt-2 text-xs text-muted">
            <p>{{ __('A verified name → answer entry matched exactly. Source') }}: <span class="text-ink">{{ data_get($cache->trace, 'source', 'cache') }}</span> · {{ __('confidence') }} {{ $pct($cache->confidence) }}.</p>
            @if($cache->explanation)<p class="mt-1">{{ $cache->explanation }}</p>@endif
          </div>
        </details>
      @endif
    </li>

    {{-- ② AI CONSENSUS (3 mechanisms) — only when the cache missed --}}
    @if($aiRan)
      <li class="card p-5">
        <div class="flex items-center justify-between gap-3 mb-3">
          <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-line/40 text-xs font-semibold">2</span>
            <span class="font-medium">{{ __('AI search') }}</span>
            <span class="text-faint text-xs">{{ __('3 mechanisms → 2-of-3 on the heading') }}</span>
          </div>
          <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $pill($consensus['agreed'] ? 'good' : 'warn') }}">{{ $consensus['agreed'] ? __('agreed') : __('diverged') }}</span>
        </div>
        <div class="flex flex-col sm:flex-row gap-2 text-sm">
          <div class="flex-1 rounded-lg border hair p-3 min-w-0">
            <p class="kicker mb-1">{{ __('Input') }}</p>
            <p class="break-words">{{ $item->localizedSourceText() }}</p>
            @if($srcTranslation)<p class="text-muted text-xs mt-0.5">↳ <em>{{ $srcTranslation }}</em></p>@endif
          </div>
          <div class="flex items-center justify-center text-faint">→</div>
          <div class="flex-1 rounded-lg border hair p-3 min-w-0">
            <p class="kicker mb-1">{{ __('Output') }}</p>
            @if($consensus['agreed'])
              <p><span class="font-mono">{{ $consensus['heading'] }}</span> <span class="text-muted">{{ \Illuminate\Support\Str::limit($anyName($consensus['heading']), 55) }}</span></p>
              <p class="text-ledger text-xs mt-0.5">{{ __(':n of :t mechanisms agreed on the heading', ['n' => $consensus['top_count'], 't' => $consensus['total']]) }}</p>
            @else
              <p class="text-muted">{{ __('the mechanisms did not agree on a heading') }}</p>
              <p class="text-amber text-xs mt-0.5">{{ $searchRan ? __('conflict → web search') : __('conflict → a human') }}</p>
            @endif
          </div>
        </div>

        {{-- The three mechanisms, each vote + deep trace --}}
        <details class="mt-3 group">
          <summary class="cursor-pointer text-xs text-muted hover:text-ink select-none list-none [&::-webkit-details-marker]:hidden flex items-center gap-1">
            <span class="transition-transform group-open:rotate-90">▸</span> {{ __('The three mechanisms & their traces') }}
          </summary>
          <div class="mt-3 space-y-3">
            @foreach($mechResults as $res)
              @php $isWinner = $consensus['agreed'] && mb_substr((string) $res->matched_code, 0, 4) === (string) $consensus['heading']; @endphp
              <div class="rounded-lg border hair p-4 {{ $isWinner ? 'ring-1 ring-ledger/30' : '' }}">
                <div class="flex items-center justify-between gap-3 flex-wrap mb-3 pb-2 border-b hair">
                  <div class="flex items-center gap-2">
                    <span class="kicker">{{ $mechLabel($res->mechanism) }}</span>
                    @if($res->model)<span class="text-faint text-xs">{{ $res->model }}</span>@endif
                  </div>
                  <div class="flex items-center gap-2">
                    <span class="font-mono text-sm">{{ $res->matched_code ?? __('no match') }}</span>
                    @if($res->matched_code)<span class="text-faint text-xs">{{ __('heading') }} {{ mb_substr((string) $res->matched_code, 0, 4) }}</span>@endif
                    <span class="text-faint text-xs tnum">{{ $pct($res->confidence) }}</span>
                  </div>
                </div>
                @include('livewire.partials.mechanism-trace')
              </div>
            @endforeach
            <p class="text-xs text-muted">{{ __('Consensus rule: the item resolves when at least 2 of the 3 mechanisms share the same 4-digit heading.') }}</p>
          </div>
        </details>
      </li>
    @endif

    {{-- ③ WEB SEARCH resolver — only when the mechanisms diverged and it ran --}}
    @if($searchRan)
      <li class="card p-5">
        <div class="flex items-center justify-between gap-3 mb-3">
          <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-line/40 text-xs font-semibold">3</span>
            <span class="font-medium">{{ __('Web search') }}</span>
            <span class="text-faint text-xs">{{ __('a thinking model looks it up online') }}</span>
          </div>
          <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $pill($searchResolved ? 'good' : 'warn') }}">{{ $searchResolved ? __('resolved') : __('to a human') }}</span>
        </div>
        <div class="flex flex-col sm:flex-row gap-2 text-sm">
          <div class="flex-1 rounded-lg border hair p-3 min-w-0">
            <p class="kicker mb-1">{{ __('Input') }}</p>
            <p class="break-words">{{ $item->localizedSourceText() }}</p>
            <p class="text-muted text-xs mt-0.5">{{ __('the three mechanisms diverged') }}</p>
          </div>
          <div class="flex items-center justify-center text-faint">→</div>
          <div class="flex-1 rounded-lg border hair p-3 min-w-0">
            <p class="kicker mb-1">{{ __('Output') }}</p>
            @if($search->matched_code)
              <p><span class="font-mono">{{ $search->matched_code }}</span> <span class="text-muted">{{ \Illuminate\Support\Str::limit(data_get($search->trace, 'heading_name') ?: $anyName($search->matched_code), 55) }}</span></p>
              <p class="text-xs mt-0.5 {{ $searchResolved ? 'text-ledger' : 'text-amber' }}">{{ __('confidence') }} {{ $pct($search->confidence) }} · {{ $searchResolved ? __('confident → taken as the answer') : __('not confident enough → a human decides') }}</p>
            @else
              <p class="text-muted">{{ __('could not confidently identify the item') }}</p>
              <p class="text-amber text-xs mt-0.5">{{ __('→ a human decides') }}</p>
            @endif
          </div>
        </div>
        @if($search->explanation)
          <details class="mt-3 group">
            <summary class="cursor-pointer text-xs text-muted hover:text-ink select-none list-none [&::-webkit-details-marker]:hidden flex items-center gap-1">
              <span class="transition-transform group-open:rotate-90">▸</span> {{ __('What the search found') }}
            </summary>
            <div class="mt-2 text-sm text-muted">
              <p>{{ $search->explanation }}</p>
              @if($search->model)<p class="text-faint text-xs mt-1">{{ $search->model }}</p>@endif
            </div>
          </details>
        @endif
      </li>
    @elseif($adj)
      {{-- Legacy: an older item settled by the (now-removed) AI adjudicator. --}}
      <li class="card p-5">
        <div class="flex items-center justify-between gap-3 mb-3">
          <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-line/40 text-xs font-semibold">3</span>
            <span class="font-medium">{{ __('AI adjudicator') }}</span>
            <span class="text-faint text-xs">{{ __('legacy — no longer in the flow') }}</span>
          </div>
          <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $pill($adj->applied ? 'good' : 'muted') }}">{{ $adj->verdict }}</span>
        </div>
        <p class="text-sm">
          @if($adj->winning_code)<span class="font-mono">{{ $adj->winning_code }}</span> <span class="text-muted">{{ \Illuminate\Support\Str::limit($anyName($adj->winning_code), 60) }}</span> <span class="text-faint text-xs">({{ $pct($adj->confidence) }})</span>@endif
          @if($adj->applied)<span class="text-ledger text-xs ml-1">· {{ __('applied → ai_resolved') }}</span>@endif
        </p>
        @if($adj->reason)<p class="text-faint text-xs mt-1">{{ $adj->reason }}</p>@endif
      </li>
    @endif

    {{-- ④ HUMAN --}}
    <li class="card p-5">
      <div class="flex items-center justify-between gap-3 mb-3">
        <div class="flex items-center gap-2.5">
          <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-line/40 text-xs font-semibold">4</span>
          <span class="font-medium">{{ __('Human') }}</span>
          <span class="text-faint text-xs">{{ __('review & confirm') }}</span>
        </div>
        <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $pill($item->resolution === 'confirmed' ? 'good' : ($item->resolution === 'rejected' ? 'bad' : ($item->final_code ? 'muted' : 'warn'))) }}">{{ $statusLabel($item->resolution) }}</span>
      </div>
      <div class="flex flex-col sm:flex-row gap-2 text-sm">
        <div class="flex-1 rounded-lg border hair p-3 min-w-0">
          <p class="kicker mb-1">{{ __('Input') }}</p>
          @if($item->final_code)
            <p><span class="font-mono">{{ $item->final_code }}</span> <span class="text-muted">{{ \Illuminate\Support\Str::limit($finalName, 55) }}</span></p>
            <p class="text-muted text-xs mt-0.5">{{ __('the AI-proposed answer') }}</p>
          @else
            <p class="text-muted">{{ __('a conflict with no confident answer') }}</p>
          @endif
        </div>
        <div class="flex items-center justify-center text-faint">→</div>
        <div class="flex-1 rounded-lg border hair p-3 min-w-0">
          <p class="kicker mb-1">{{ __('Output') }}</p>
          @if($item->resolution === 'confirmed')
            <p class="text-ledger">{{ __('confirmed') }}</p>
            <p class="text-muted text-xs mt-0.5">{{ optional($item->confirmedBy)->name ?? optional($item->confirmedBy)->email }}@if($item->confirmed_at) · {{ $item->confirmed_at->diffForHumans() }}@endif</p>
          @elseif($item->resolution === 'rejected')
            <p class="text-stamp">{{ __('rejected') }}</p>
          @elseif($item->final_code)
            <p class="text-muted">{{ __('auto-accepted — a human can still override it') }}</p>
          @else
            <p class="text-amber">{{ __('waiting for a human decision') }}</p>
          @endif
        </div>
      </div>
    </li>
  </ol>

  {{-- Reference ("gold") labels — a benchmark hint for the reviewer. This is NEVER
       part of how the item was classified and is NEVER shown to the AI. --}}
  @if($gold->isNotEmpty())
    <div class="card-flat p-4 mt-5 text-sm">
      <span class="kicker">{{ __('Reference (gold)') }} <span class="text-faint">· {{ __('benchmark only — never shown to the AI') }}</span></span>
      <div class="mt-1.5 space-y-1.5">
        @foreach($gold as $gl)
          @php
            $gShow = $gl->is_service ? __('service') : ($gl->code ?? $gl->heading);
            $gMatch = $gl->is_service
                ? ($item->kind !== null ? (($item->kind === 'service') === (bool) $gl->is_service) : null)
                : ($item->final_code ? (($gl->code && (string) $item->final_code === (string) $gl->code) || (! $gl->code && $gl->heading && mb_substr((string) $item->final_code, 0, 4) === $gl->heading)) : null);
            $disputed = data_get($gl->meta, 'crosscheck') === 'disagree';
          @endphp
          <div class="flex items-start gap-2">
            <span class="uppercase tracking-wide text-faint w-14 shrink-0">{{ $gl->source }}</span>
            <span class="font-mono shrink-0">{{ $gShow }}</span>
            @if($gMatch === true)<span class="text-ledger">✓</span>@elseif($gMatch === false)<span class="text-stamp">✕</span>@endif
            <span class="text-muted flex-1 min-w-0 break-words">
              @if($gl->source === 'fedor' && $gl->tier){{ $gl->tier }}@endif
              @if($disputed) · {{ __('models disputed') }}@if(data_get($gl->meta, 'gpt_heading')) (gpt {{ data_get($gl->meta, 'gpt_heading') }})@endif @endif
              @if($gl->category) · {{ $gl->category }}@endif
              @if(data_get($gl->meta, 'note'))<span class="text-faint block mt-0.5">{{ \Illuminate\Support\Str::limit(data_get($gl->meta, 'note'), 160) }}</span>@endif
            </span>
          </div>
        @endforeach
      </div>
    </div>
  @endif
</section>
