@php
    $g = $gate;
    $pctv = fn ($v) => $v !== null ? number_format((float) $v * 100, 0).'%' : '—';
@endphp
<div class="rounded-lg border hair p-3 bg-paper/40">
  <span class="kicker">{{ __('Gate (auto-confirm)') }}</span>
  <div class="mt-1 text-xs space-y-0.5">
    @if(array_key_exists('confidence', $g))
      <div>
        {{ __('Confidence') }}: <span class="tnum">{{ $pctv($g['confidence'] ?? null) }}</span>
        @if(isset($g['auto_confirm']) && ($g['confidence'] ?? null) !== null)
          <span class="{{ $g['confidence'] >= $g['auto_confirm'] ? 'text-ledger' : 'text-stamp' }}">{{ $g['confidence'] >= $g['auto_confirm'] ? '≥' : '<' }} {{ number_format((float) $g['auto_confirm'] * 100, 0) }}%</span>
        @endif
      </div>
    @endif
    @if(array_key_exists('semantic_sim', $g))
      <div>
        {{ __('Semantic backing') }}: <span class="tnum">{{ $pctv($g['semantic_sim'] ?? null) }}</span>
        @if(isset($g['min_semantic']) && ($g['semantic_sim'] ?? null) !== null)
          <span class="{{ $g['semantic_sim'] >= $g['min_semantic'] ? 'text-ledger' : 'text-stamp' }}">{{ $g['semantic_sim'] >= $g['min_semantic'] ? '≥' : '<' }} {{ number_format((float) $g['min_semantic'] * 100, 0) }}%</span>
        @endif
      </div>
    @endif
    <div>→ {{ __('Status') }}: <span class="font-medium">{{ str_replace('_', ' ', $g['status'] ?? '') }}</span></div>
  </div>
</div>
