<section class="p-5 sm:p-8 max-w-[1180px]">
  @php
    $colLabels = ['memory' => __('Memory'), 'vector' => __('Vector'), 'broker' => __('Broker'), 'direct' => __('Direct'), 'majority' => $majorityLabel, 'search' => __('Web search'), 'overall' => __('Overall')];
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
      </div>
    </div>

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
