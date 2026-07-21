<section class="p-5 sm:p-8 max-w-[1080px]">
  @php
    $colLabels = ['memory' => __('Memory'), 'vector' => __('Vector'), 'broker' => __('Broker'), 'direct' => __('Direct'), 'majority' => __('Majority'), 'search' => __('Web search'), 'overall' => __('Overall')];
  @endphp

  <div class="mb-5">
    <a href="{{ route('testing') }}" class="text-sm text-muted hover:underline">← {{ __('Testing') }}</a>
    <h1 class="font-display text-3xl mt-1">{{ __('Compare runs') }}</h1>
  </div>

  @if($mismatch)
    <div class="card p-6 text-sm text-stamp">{{ __('Those two runs belong to different datasets — pick two runs of the same dataset.') }}</div>
  @elseif(! $ready)
    <div class="card p-6 text-sm text-muted">{{ __('Pick two runs of the same dataset to compare (from a dataset page).') }}</div>
  @else
    <div class="grid sm:grid-cols-2 gap-4 mb-6">
      <div class="card p-4"><p class="kicker mb-1">A · #{{ $runA->id }}</p><p class="text-sm">{{ $runA->description }}</p><p class="text-xs text-faint mt-1">{{ $runA->created_at?->format('Y-m-d H:i') }}</p></div>
      <div class="card p-4"><p class="kicker mb-1">B · #{{ $runB->id }}</p><p class="text-sm">{{ $runB->description }}</p><p class="text-xs text-faint mt-1">{{ $runB->created_at?->format('Y-m-d H:i') }}</p></div>
    </div>

    {{-- Accuracy deltas --}}
    <div class="card p-0 overflow-hidden mb-6">
      <table class="w-full text-sm">
        <thead class="text-muted text-left">
          <tr class="border-b hair">
            <th class="px-4 py-3 font-medium">{{ __('Mechanism') }}</th>
            <th class="px-4 py-3 font-medium text-right">A</th>
            <th class="px-4 py-3 font-medium text-right">B</th>
            <th class="px-4 py-3 font-medium text-right">Δ</th>
          </tr>
        </thead>
        <tbody>
          @foreach($colLabels as $col => $label)
            @php $d = $deltas[$col] ?? null; @endphp
            <tr class="border-b hair {{ $col === 'overall' ? 'font-medium bg-surface' : '' }}">
              <td class="px-4 py-3">{{ $label }}</td>
              <td class="px-4 py-3 text-right tnum">{{ $d && $d['a'] !== null ? $d['a'].'%' : '—' }}</td>
              <td class="px-4 py-3 text-right tnum">{{ $d && $d['b'] !== null ? $d['b'].'%' : '—' }}</td>
              <td class="px-4 py-3 text-right tnum font-medium {{ $d && $d['delta'] !== null ? ($d['delta'] > 0 ? 'text-ledger' : ($d['delta'] < 0 ? 'text-stamp' : '')) : '' }}">
                {{ $d && $d['delta'] !== null ? ($d['delta'] > 0 ? '+' : '').$d['delta'] : '—' }}
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    {{-- Rows whose overall answer flipped --}}
    <p class="font-medium mb-2">{{ __('Changed rows (:n)', ['n' => $flipTotal]) }}</p>
    <div class="card p-0 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="text-muted text-left">
          <tr class="border-b hair">
            <th class="px-4 py-3 font-medium">{{ __('Item') }}</th>
            <th class="px-4 py-3 font-medium">{{ __('Exp.') }}</th>
            <th class="px-4 py-3 font-medium">A</th>
            <th class="px-4 py-3 font-medium">B</th>
          </tr>
        </thead>
        <tbody>
          @forelse($page as $r)
            <tr class="border-b hair">
              <td class="px-4 py-2.5 max-w-[320px] truncate" title="{{ $r['name'] }}">{{ $r['name'] }}</td>
              <td class="px-4 py-2.5 font-mono">{{ $r['expected'] ?? '—' }}</td>
              <td class="px-4 py-2.5 font-mono {{ $r['okA'] ? 'text-ledger' : 'text-stamp' }}">{{ $r['a'] }}{{ $r['okA'] ? ' ✓' : ' ✗' }}</td>
              <td class="px-4 py-2.5 font-mono {{ $r['okB'] ? 'text-ledger' : 'text-stamp' }}">{{ $r['b'] }}{{ $r['okB'] ? ' ✓' : ' ✗' }}</td>
            </tr>
          @empty
            <tr><td colspan="4" class="px-4 py-6 text-center text-muted">{{ __('No rows changed their overall answer.') }}</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="mt-4">{{ $page->onEachSide(1)->links() }}</div>
  @endif
</section>
