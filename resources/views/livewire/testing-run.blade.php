<section class="p-5 sm:p-8 max-w-[1180px]">
  @php
    // 'majority' here is the per-row detail table's consensus column (unchanged shape),
    // not the funnel breakdown below — see $funnel for that.
    $colLabels = ['memory' => __('Memory'), 'vector' => __('Vector'), 'broker' => __('Broker'), 'direct' => __('Direct'), 'majority' => __('Mechanism consensus'), 'search' => __('Web search'), 'overall' => __('Overall'), 'sorter' => __('Sorter')];
    $acc = fn ($b) => ($b && ($b['ran'] ?? 0) > 0) ? round(100 * $b['correct'] / $b['ran']) : null;
  @endphp

  <div class="mb-5">
    <a href="{{ route('testing.dataset', $run->dataset) }}" class="text-sm text-muted hover:underline">← {{ $run->dataset->name }}</a>
    <h1 class="font-display text-3xl mt-1">{{ __('Run') }} #{{ $run->id }}</h1>
    <p class="text-sm text-muted mt-1">{{ $run->description }}</p>
  </div>

  @php
    $fmtDur = function ($s) {
        if ($s === null) return '—';
        $m = intdiv($s, 60); $sec = $s % 60;
        return $m > 0 ? "{$m}m {$sec}s" : "{$sec}s";
    };
  @endphp
  <div class="grid grid-cols-2 sm:flex sm:flex-wrap gap-4 mb-6">
    <div class="card-flat p-4 min-w-[130px]">
      <p class="kicker mb-1.5">{{ __('Duration') }}{{ $complete ? '' : ' · '.__('running') }}</p>
      <p class="font-display text-2xl tnum">{{ $fmtDur($durationSeconds) }}</p>
    </div>
    <div class="card-flat p-4 min-w-[130px]">
      <p class="kicker mb-1.5">{{ __('Tokens') }}{{ $complete ? '' : ' · '.__('so far') }}</p>
      <p class="font-display text-2xl tnum">{{ number_format($tokens, 0, '.', ' ') }}</p>
    </div>
  </div>

  {{-- Progress (polls until done) --}}
  @unless($complete)
    <div class="card p-5 mb-6" wire:poll.1500ms>
      <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
        <div class="flex items-center gap-2.5">
          <span class="w-7 h-7 grid place-items-center rounded-full bg-line/40 animate-pulse">…</span>
          <span class="font-medium">{{ __('Classifying… :done / :total', ['done' => $done, 'total' => $total]) }}</span>
        </div>
        <span class="text-sm text-muted">{{ $pct }}%</span>
      </div>
      <div class="h-2 rounded-full bg-line/40 overflow-hidden"><div class="h-full bg-ink" style="width: {{ $pct }}%"></div></div>
    </div>
  @endunless

  {{-- Accuracy --}}
  @if($complete)
    <div class="card p-0 overflow-hidden mb-6">
      <div class="overflow-x-auto">
        @if($funnelRows)
          @php
            $funnelPromoted = collect($funnelRows)->sum('promoted');
            $lastStep = null;
          @endphp
          <table class="w-full text-sm">
            <thead class="text-muted text-left">
              <tr class="border-b hair">
                <th class="px-4 py-2 font-medium" colspan="4">{{ __('Main algorithm') }}</th>
                <th class="px-4 py-2 font-medium text-right" colspan="2">{{ __('Reference accuracy check') }}</th>
              </tr>
              <tr class="border-b hair">
                <th class="px-4 py-3 font-medium">{{ __('Step') }}</th>
                <th class="px-4 py-3 font-medium">{{ __('Mechanism') }}</th>
                <th class="px-4 py-3 font-medium text-right">{{ __('Ran') }}</th>
                <th class="px-4 py-3 font-medium text-right">{{ __('Sent to memory & training') }}</th>
                <th class="px-4 py-3 font-medium text-right">{{ __('Correct') }}</th>
                <th class="px-4 py-3 font-medium text-right">{{ __('Accuracy') }}</th>
              </tr>
            </thead>
            <tbody>
              @foreach($funnelRows as $r)
                @php
                  $a = $acc($r['bucket']);
                  $showStep = $r['step'] !== $lastStep;
                  $rowspan = $showStep ? collect($funnelRows)->where('step', $r['step'])->count() : null;
                  $lastStep = $r['step'];
                @endphp
                <tr class="border-b hair">
                  @if($showStep)
                    <td class="px-4 py-3 tnum text-muted align-top" rowspan="{{ $rowspan }}">{{ $r['step'] }}</td>
                  @endif
                  <td class="px-4 py-3">{{ $r['label'] }}</td>
                  <td class="px-4 py-3 text-right tnum">{{ (int) ($r['bucket']['ran'] ?? 0) }}</td>
                  <td class="px-4 py-3 text-right tnum">{{ $r['promoted'] !== null ? number_format($r['promoted']) : '—' }}</td>
                  <td class="px-4 py-3 text-right tnum">{{ (int) ($r['bucket']['correct'] ?? 0) }}</td>
                  <td class="px-4 py-3 text-right tnum font-medium">{{ $a !== null ? $a.'%' : '—' }}</td>
                </tr>
              @endforeach
              @php $aOverall = $acc($accuracy['overall'] ?? null); @endphp
              <tr class="border-b hair font-medium bg-surface">
                <td class="px-4 py-3"></td>
                <td class="px-4 py-3">{{ __('Overall') }}</td>
                <td class="px-4 py-3 text-right tnum">{{ (int) ($accuracy['overall']['ran'] ?? 0) }}</td>
                <td class="px-4 py-3 text-right tnum">{{ number_format($funnelPromoted) }}</td>
                <td class="px-4 py-3 text-right tnum">{{ (int) ($accuracy['overall']['correct'] ?? 0) }}</td>
                <td class="px-4 py-3 text-right tnum">{{ $aOverall !== null ? $aOverall.'%' : '—' }}</td>
              </tr>
            </tbody>
          </table>
        @else
          {{-- Fallback for runs scored before the funnel breakdown existed. --}}
          <table class="w-full text-sm">
            <thead class="text-muted text-left">
              <tr class="border-b hair">
                <th class="px-4 py-3 font-medium">{{ __('Mechanism') }}</th>
                <th class="px-4 py-3 font-medium text-right">{{ __('Ran') }}</th>
                <th class="px-4 py-3 font-medium text-right">{{ __('Correct') }}</th>
                <th class="px-4 py-3 font-medium text-right">{{ __('Accuracy') }}</th>
              </tr>
            </thead>
            <tbody>
              @foreach($colLabels as $col => $label)
                @php $b = $accuracy[$col] ?? null; $a = $acc($b); @endphp
                <tr class="border-b hair {{ $col === 'overall' ? 'font-medium bg-surface' : '' }}">
                  <td class="px-4 py-3">{{ $label }}</td>
                  <td class="px-4 py-3 text-right tnum">{{ (int) ($b['ran'] ?? 0) }}</td>
                  <td class="px-4 py-3 text-right tnum">{{ (int) ($b['correct'] ?? 0) }}</td>
                  <td class="px-4 py-3 text-right tnum font-medium">{{ $a !== null ? $a.'%' : '—' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif
      </div>
    </div>

    {{-- Sorter: the model alone, on the KIND of line (good / service / trash) — scored over
         every row that names one, including kind-only rows (TRASH / GOOD / SERVICE in column B). --}}
    @if($sorter)
      @php
        $cf = $sorter['confusion'] ?? [];
        $n = fn ($e, $p) => (int) ($cf[$e][$p] ?? 0);
        $rowSum = fn ($e) => array_sum($cf[$e] ?? []);
        $pr = fn ($tp, $fp, $fn) => [$tp + $fp > 0 ? round(100 * $tp / ($tp + $fp), 1) : null, $tp + $fn > 0 ? round(100 * $tp / ($tp + $fn), 1) : null];
        $kinds = ['good' => __('good'), 'service' => __('service'), 'trash' => __('trash')];
        $sc = $accuracy['sorter'];
        // A kind the dataset has no rows of: its precision/recall say nothing — show "—".
        [$tP, $tR] = $rowSum('trash') > 0 ? $pr($n('trash', 'trash'), $n('good', 'trash') + $n('service', 'trash'), $rowSum('trash') - $n('trash', 'trash')) : [null, null];
        $wr = $sorter['with_rules'] ?? ['tp' => 0, 'fp' => 0, 'fn' => 0];
        [$wP, $wR] = $rowSum('trash') > 0 ? $pr($wr['tp'], $wr['fp'], $wr['fn']) : [null, null];
        [$sP, $sR] = $rowSum('service') > 0 ? $pr($n('service', 'service'), $n('good', 'service') + $n('trash', 'service'), $rowSum('service') - $n('service', 'service')) : [null, null];
        $fmt = fn ($v) => $v === null ? '—' : $v.'%';
      @endphp
      <p class="font-medium mb-2">{{ __('Sorter') }} <span class="text-sm text-muted font-normal">· {{ __('a good, a service, or no product at all? Trash at p ≥ :t, as in production.', ['t' => $sorter['threshold'] !== null ? rtrim(rtrim(number_format((float) $sorter['threshold'], 5, '.', ''), '0'), '.') : '—']) }}</span></p>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">
        <div class="card-flat p-4">
          <p class="kicker mb-1.5">{{ __('Kind correct') }}</p>
          <p class="font-display text-2xl tnum">{{ $fmt($acc($sc)) }}</p>
          <p class="text-xs text-muted mt-1">{{ __(':correct of :ran rows', ['correct' => $sc['correct'], 'ran' => $sc['ran']]) }}</p>
        </div>
        <div class="card-flat p-4">
          <p class="kicker mb-1.5">{{ __('Trash · sorter') }}</p>
          <p class="font-display text-2xl tnum">{{ $fmt($tP) }}</p>
          <p class="text-xs text-muted mt-1">{{ __('precision · recall :r', ['r' => $fmt($tR)]) }}</p>
        </div>
        <div class="card-flat p-4">
          <p class="kicker mb-1.5">{{ __('Trash · rules + sorter') }}</p>
          <p class="font-display text-2xl tnum">{{ $fmt($wP) }}</p>
          <p class="text-xs text-muted mt-1">{{ __('precision · recall :r', ['r' => $fmt($wR)]) }}</p>
        </div>
        <div class="card-flat p-4">
          <p class="kicker mb-1.5">{{ __('Service') }}</p>
          <p class="font-display text-2xl tnum">{{ $fmt($sP) }}</p>
          <p class="text-xs text-muted mt-1">{{ __('precision · recall :r', ['r' => $fmt($sR)]) }}</p>
        </div>
      </div>
      <div class="card p-0 overflow-hidden mb-6">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-muted text-left">
              <tr class="border-b hair">
                <th class="px-4 py-3 font-medium">{{ __('Expected ↓ · sorter said →') }}</th>
                @foreach([...$kinds, 'unsure' => __('unsure')] as $label)
                  <th class="px-4 py-3 font-medium text-right">{{ $label }}</th>
                @endforeach
                <th class="px-4 py-3 font-medium text-right">{{ __('Rows') }}</th>
              </tr>
            </thead>
            <tbody>
              @foreach($kinds as $e => $label)
                @continue($rowSum($e) === 0)
                <tr class="border-b hair">
                  <td class="px-4 py-3">{{ $label }}</td>
                  @foreach(['good', 'service', 'trash', 'unsure'] as $p)
                    <td class="px-4 py-3 text-right tnum {{ $p === $e ? 'text-ledger font-medium' : ($n($e, $p) > 0 && $p !== 'unsure' ? 'text-stamp' : '') }}">{{ $n($e, $p) ?: '·' }}</td>
                  @endforeach
                  <td class="px-4 py-3 text-right tnum text-muted">{{ $rowSum($e) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <p class="px-4 py-3 text-xs text-muted border-t hair">{{ __('"unsure": its top class is trash, but below the threshold — in production it then acts on nothing. "Rules + sorter" is the whole trash step: a rule fires, or the sorter is sure.') }}</p>
      </div>
    @endif

    {{-- Per-row detail --}}
    <p class="font-medium mb-2">{{ __('Per-row detail') }}</p>
    <div class="card p-0 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm whitespace-nowrap">
          <thead class="text-muted text-left">
            <tr class="border-b hair">
              <th class="px-3 py-3 font-medium">{{ __('Item') }}</th>
              <th class="px-3 py-3 font-medium">{{ __('Exp.') }}</th>
              @foreach($colLabels as $label)
                <th class="px-3 py-3 font-medium">{{ $label }}</th>
              @endforeach
              <th class="px-3 py-3"></th>
            </tr>
          </thead>
          <tbody>
            @foreach($detail as $d)
              <tr class="border-b hair hover:bg-surface">
                <td class="px-3 py-2.5 max-w-[260px] truncate" title="{{ $d['name'] }}">{{ $d['name'] }}</td>
                <td class="px-3 py-2.5 font-mono">{{ $d['expected'] ?? '—' }}</td>
                @foreach(array_keys($colLabels) as $col)
                  @php $c = $d['cells'][$col] ?? null; @endphp
                  <td class="px-3 py-2.5 font-mono">
                    @if($c === null)
                      <span class="text-faint">·</span>
                    @else
                      <span class="{{ $c['ok'] ? 'text-ledger' : 'text-stamp' }}">{{ $c['heading'] }}{{ $c['ok'] ? ' ✓' : ' ✗' }}</span>
                    @endif
                  </td>
                @endforeach
                <td class="px-3 py-2.5">
                  @if($d['item_id'])
                    <a href="{{ route('review.decision', $d['item_id']) }}" class="text-xs text-muted hover:underline">{{ __('trace') }}</a>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
    <div class="mt-4">{{ $rowsPage->onEachSide(1)->links() }}</div>
  @endif
</section>
