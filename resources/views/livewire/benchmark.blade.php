<section class="p-5 sm:p-8 max-w-[1080px]">
  @php
    $pct = fn ($a, $b) => $b > 0 ? round($a / $b * 100) : null;
    $srcMeta = [
      'ivan'  => ['label' => 'Ivan',  'sub' => __('full 10-digit code · one model')],
      'fedor' => ['label' => 'Fedor', 'sub' => __('4-digit heading + good/service · two models agree')],
    ];
    // Which agreement metrics each reference actually supports.
    $metricsFor = fn ($s, $a) => $s === 'ivan'
      ? [[__('Full code'), $a['full_agree'], $a['full_total']], [__('Heading (4)'), $a['heading_agree'], $a['heading_total']]]
      : [[__('Heading (4)'), $a['heading_agree'], $a['heading_total']], [__('Good / service'), $a['service_agree'], $a['service_total']]];
    $statuses = ['disagree' => __('Disagree'), 'agree' => __('Agree'), 'no_code' => __('No code'), 'no_ref' => __('No gold code'), 'unclassified' => __('Not run yet'), 'all' => __('All matched')];
    $stColor = fn ($s) => match ($s) {
      'agree' => 'bg-ledger/12 text-ledger', 'disagree' => 'bg-stamp/12 text-stamp',
      'no_code' => 'bg-amber/15 text-amber', default => 'bg-line/40 text-muted',
    };
    $cd = fn ($c) => $c !== null && $c !== '' ? $c : '—';
  @endphp

  <div class="mb-5 flex items-end justify-between flex-wrap gap-3">
    <div>
      <p class="kicker mb-1.5">{{ __('Reference agreement') }}</p>
      <h1 class="font-display text-4xl">{{ __('Benchmark') }}</h1>
    </div>
  </div>

  <p class="text-sm text-muted mb-5 max-w-[70ch]">
    {{ __('How well our classifier matches two external reference sets, joined by product name. Each reference is AI-labelled and can itself be wrong — a disagreement is a candidate to check, not proof we erred. Ivan gives the full 10-digit code; Fedor gives the 4-digit heading + good/service (two models agreed).') }}
  </p>

  {{-- Per-reference score cards --}}
  <div class="grid md:grid-cols-2 gap-4 mb-4">
    @foreach(['ivan', 'fedor'] as $s)
      @php $a = $sources[$s] ?? null; @endphp
      <div class="card p-5">
        <div class="flex items-baseline justify-between gap-2 mb-1">
          <h2 class="font-display text-2xl">{{ $srcMeta[$s]['label'] }}</h2>
          <span class="text-faint text-xs">{{ $srcMeta[$s]['sub'] }}</span>
        </div>
        @if(!$a)
          <p class="text-muted text-sm mt-3">{{ __('Not imported yet — run') }} <code class="text-xs">benchmark:import-gold</code>.</p>
        @else
          <p class="text-sm text-muted mb-3">
            {{ __('Classified') }} <span class="tnum font-medium text-ink">{{ $a['matched'] }}</span>
            {{ __('of') }} <span class="tnum">{{ $a['total'] }}</span>
            <span class="text-faint">({{ $pct($a['matched'], $a['total']) ?? 0 }}% {{ __('coverage') }})</span>
          </p>
          @foreach($metricsFor($s, $a) as [$label, $ag, $tot])
            <div class="mb-2.5">
              <div class="flex items-center justify-between text-sm mb-1">
                <span class="text-muted">{{ $label }}</span>
                <span class="tnum">
                  @if($tot > 0)<span class="font-medium">{{ $pct($ag, $tot) }}%</span> <span class="text-faint">({{ $ag }}/{{ $tot }})</span>@else<span class="text-faint">{{ __('no data yet') }}</span>@endif
                </span>
              </div>
              <div class="h-2 rounded-full bg-line/40 overflow-hidden">
                <span class="bg-ledger block h-full" style="width:{{ $tot > 0 ? $ag / $tot * 100 : 0 }}%"></span>
              </div>
            </div>
          @endforeach
          <div class="flex gap-4 text-xs text-faint mt-3 pt-3 border-t hair">
            <span class="text-stamp">✕ {{ __('disagree') }} <span class="tnum">{{ $a['disagree'] }}</span></span>
            <span class="text-amber">{{ __('no code') }} <span class="tnum">{{ $a['no_code'] }}</span></span>
            <span class="ml-auto">{{ __('not run') }} <span class="tnum">{{ $a['unclassified'] }}</span></span>
          </div>
        @endif
      </div>
    @endforeach
  </div>

  {{-- Overlap triangulation --}}
  @if(($overlap['shared'] ?? 0) > 0)
    <div class="card-flat p-4 mb-5 text-sm">
      <span class="kicker">{{ __('Where both references cover the same item') }}</span>
      <div class="flex items-center gap-4 flex-wrap mt-2">
        <span class="text-muted">{{ __('Shared names') }} <span class="tnum text-ink font-medium">{{ $overlap['shared'] }}</span></span>
        <span class="text-muted">{{ __('classified') }} <span class="tnum">{{ $overlap['classified'] }}</span></span>
        @if($overlap['classified'] > 0)
          <span class="text-ledger">{{ __('agree with both') }} <span class="tnum font-medium">{{ $overlap['both'] }}</span></span>
          <span class="text-amber">{{ __('one') }} <span class="tnum">{{ $overlap['one'] }}</span></span>
          <span class="text-stamp">{{ __('neither') }} <span class="tnum">{{ $overlap['neither'] }}</span></span>
        @endif
      </div>
    </div>
  @endif

  {{-- Filters --}}
  <div class="flex flex-wrap items-center gap-2 mb-3">
    <div class="inline-flex rounded-lg border hair overflow-hidden text-sm">
      @foreach(['all' => __('Both'), 'ivan' => 'Ivan', 'fedor' => 'Fedor'] as $key => $label)
        <button wire:click="setSource('{{ $key }}')" class="px-3 py-1.5 {{ $source === $key ? 'bg-ink text-paper' : 'bg-surface text-muted hover:text-ink' }} {{ !$loop->first ? 'border-l hair' : '' }}">{{ $label }}</button>
      @endforeach
    </div>
    <div class="flex flex-wrap gap-2">
      @foreach($statuses as $key => $label)
        <button wire:click="setStatus('{{ $key }}')"
                class="px-3 py-1.5 rounded-lg text-sm border hair transition {{ $status === $key ? 'bg-ink text-paper border-ink' : 'bg-surface hover:border-ink' }}">{{ $label }}</button>
      @endforeach
    </div>
  </div>

  {{-- Comparison table --}}
  <div class="card overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="text-left text-faint border-b hair">
          <tr>
            <th class="font-normal px-4 py-2.5">{{ __('Item') }}</th>
            <th class="font-normal px-3 py-2.5">{{ __('Ref') }}</th>
            <th class="font-normal px-3 py-2.5">{{ __('Ours') }}</th>
            <th class="font-normal px-3 py-2.5">{{ __('Gold') }}</th>
            <th class="font-normal px-3 py-2.5 text-center">{{ __('Match') }}</th>
            <th class="font-normal px-3 py-2.5"></th>
          </tr>
        </thead>
        <tbody>
          @forelse($page as $r)
            <tr class="border-b hair align-top">
              <td class="px-4 py-2.5 max-w-[320px]">
                <span class="px-1.5 py-0.5 rounded text-[11px] font-medium {{ $stColor($r['status']) }}">{{ str_replace('_', ' ', $r['status']) }}</span>
                <p class="mt-1 break-words">{{ \Illuminate\Support\Str::limit($r['name'], 90) }}</p>
              </td>
              <td class="px-3 py-2.5 text-faint capitalize">{{ $r['source'] }}</td>
              <td class="px-3 py-2.5">
                @if($r['our_code'])
                  <span class="font-mono">{{ $r['our_code'] }}</span>
                  <span class="text-faint block text-xs">{{ \Illuminate\Support\Str::limit($catalogNames[(string) $r['our_code']] ?? '', 34) }}</span>
                @elseif($r['status'] === 'unclassified')
                  <span class="text-faint">{{ __('not run') }}</span>
                @else
                  <span class="text-amber">{{ str_replace('_', ' ', $r['resolution'] ?? '—') }}</span>
                @endif
              </td>
              <td class="px-3 py-2.5">
                @if($r['gold_service'])
                  <span class="text-muted">{{ __('service') }}</span>
                @else
                  <span class="font-mono">{{ $cd($r['gold_code'] ?? $r['gold_heading']) }}</span>
                @endif
              </td>
              <td class="px-3 py-2.5 text-center">
                @php
                  $m = $r['source'] === 'ivan' ? $r['full_match'] : ($r['gold_service'] ? $r['service_match'] : $r['heading_match']);
                  $h = $r['heading_match'];
                @endphp
                @if($m === true)<span class="text-ledger">✓</span>
                @elseif($m === false)
                  <span class="text-stamp">✕</span>
                  @if($r['source'] === 'ivan' && $h === true)<span class="text-amber text-xs block">{{ __('head ✓') }}</span>@endif
                @else<span class="text-faint">–</span>@endif
              </td>
              <td class="px-3 py-2.5">
                @if($r['item_id'])
                  <a href="{{ route('review.decision', $r['item_id']) }}" target="_blank" class="text-xs text-muted hover:text-ink underline decoration-dotted whitespace-nowrap">🔍 {{ __('flow') }}</a>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="6" class="px-4 py-10 text-center text-muted">{{ __('Nothing matches this filter.') }}</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-5">{{ $page->onEachSide(1)->links() }}</div>
</section>
