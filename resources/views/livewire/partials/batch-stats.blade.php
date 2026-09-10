{{-- Always-on per-batch classification funnel: how each step resolved the batch, the share
     of each, how many answers went to memory & training, and the total recognition time.
     Expects: $stats (App\Services\Classify\BatchStats::for). --}}
@php
  $fmtDur = function (?int $s) {
      if ($s === null) return '—';
      $d = intdiv($s, 86400); $s %= 86400;
      return ($d > 0 ? $d.'d ' : '').gmdate('H:i:s', $s);
  };
  $total = (int) $stats['total'];
@endphp
<div class="card p-5">
  <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
    <div class="flex items-center gap-2.5">
      <span class="kicker">{{ __('Batch statistics') }}</span>
      @unless($stats['complete'])
        <span class="px-2 py-0.5 rounded-md text-xs font-medium bg-amber/15 text-amber animate-pulse">{{ __('in progress') }}</span>
      @endunless
    </div>
    <div class="flex items-center gap-4 text-sm">
      <span class="text-muted">{{ __('Recognition time') }} <span class="text-ink font-medium tnum">{{ $fmtDur($stats['seconds']) }}</span></span>
      <span class="text-muted">{{ number_format($stats['answered']) }}<span class="text-faint">/{{ number_format($total) }}</span> {{ __('answered') }}</span>
      @if($stats['processing'] > 0)<span class="text-amber tnum">{{ number_format($stats['processing']) }} {{ __('processing') }}</span>@endif
    </div>
  </div>

  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-left text-muted border-b hair">
          <th class="font-medium px-3 py-2 w-10">{{ __('Step') }}</th>
          <th class="font-medium px-3 py-2">{{ __('Mechanism') }}</th>
          <th class="font-medium px-3 py-2 text-right">{{ __('Ran') }}</th>
          <th class="font-medium px-3 py-2 text-right">%</th>
          <th class="font-medium px-3 py-2 text-right">{{ __('Sent to memory & training') }}</th>
        </tr>
      </thead>
      <tbody>
        @foreach($stats['rows'] as $r)
          <tr class="border-b hair">
            <td class="px-3 py-2 text-faint tnum">{{ $loop->first || $stats['rows'][$loop->index-1]['step'] !== $r['step'] ? $r['step'] : '' }}</td>
            <td class="px-3 py-2">{{ __($r['label']) }}</td>
            <td class="px-3 py-2 text-right tnum">{{ number_format($r['ran']) }}</td>
            <td class="px-3 py-2 text-right tnum text-muted">{{ rtrim(rtrim(number_format($r['pct'], 1), '0'), '.') }}%</td>
            <td class="px-3 py-2 text-right tnum {{ $r['memory'] === null ? 'text-faint' : 'text-muted' }}">{{ $r['memory'] === null ? '—' : number_format($r['memory']) }}</td>
          </tr>
        @endforeach
        @if($stats['processing'] > 0)
          <tr class="border-b hair text-amber">
            <td class="px-3 py-2"></td>
            <td class="px-3 py-2">{{ __('Still processing') }}</td>
            <td class="px-3 py-2 text-right tnum">{{ number_format($stats['processing']) }}</td>
            <td class="px-3 py-2 text-right tnum">{{ $total > 0 ? rtrim(rtrim(number_format($stats['processing'] / $total * 100, 1), '0'), '.') : 0 }}%</td>
            <td class="px-3 py-2"></td>
          </tr>
        @endif
        <tr class="font-medium">
          <td class="px-3 py-2"></td>
          <td class="px-3 py-2">{{ __('Overall') }}</td>
          <td class="px-3 py-2 text-right tnum">{{ number_format($total) }}</td>
          <td class="px-3 py-2 text-right tnum">{{ $stats['complete'] ? '100%' : '—' }}</td>
          <td class="px-3 py-2 text-right tnum text-muted">{{ number_format($stats['memory_promoted']) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
