{{-- Shared classification results table — used on both the Classify page and the
     Review queue. View-only: the item name links to the decision page, where the
     confirm/reject controls live.
     Expects: $rows (iterable of ClassificationItem, with `finalCode` + `results`
     loaded), $headingNames (code => localized rubricator name). --}}
@php
  $kindBadge = fn ($k) => $k === 'service' ? 'bg-amber/15 text-amber' : ($k === 'good' ? 'bg-ledger/12 text-ledger' : 'bg-line/40 text-muted');
  $statusBadge = fn ($s) => match ($s) {
      'agreed', 'confirmed' => 'bg-ledger/12 text-ledger',
      'ai_resolved' => 'bg-ink/10 text-ink',
      'blocked_on_fact' => 'bg-amber/15 text-amber',
      'conflict' => 'bg-stamp/12 text-stamp',
      default => 'bg-line/40 text-muted', // no_match, rejected, pending
  };
  // The method that produced the final answer: memory (cache) / web research / local ai.
  $sourceOf = function ($item) {
      if ($item->results->firstWhere('mechanism', 'cache') !== null) return 'memory';
      if ($item->final_code && optional($item->results->firstWhere('mechanism', 'search'))->matched_code === (string) $item->final_code) return 'web research';
      if ($item->final_code !== null && $item->final_code !== '') return 'local ai';
      return null; // not resolved yet
  };
  $codeName = fn ($item) => $item->finalCode?->localizedName() ?: ($headingNames[(string) $item->final_code] ?? '');
@endphp

<div class="card-flat overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-left text-muted border-b hair bg-paper/50">
          <th class="font-medium px-4 py-3">{{ __('Item') }}</th>
          <th class="font-medium px-4 py-3">{{ __('Kind') }}</th>
          <th class="font-medium px-4 py-3">{{ __('Code') }}</th>
          <th class="font-medium px-4 py-3">{{ __('Matched name') }}</th>
          <th class="font-medium px-4 py-3">{{ __('Found by') }}</th>
          <th class="font-medium px-4 py-3">{{ __('Status') }}</th>
        </tr>
      </thead>
      <tbody>
        @forelse($rows as $r)
          @php $src = $sourceOf($r); @endphp
          <tr wire:key="row-{{ $r->id }}" class="border-b hair last:border-0 align-top">
            <td class="px-4 py-3 max-w-[260px]">
              <a href="{{ route('review.decision', $r->id) }}" target="_blank"
                 class="text-ink hover:text-stamp underline decoration-dotted decoration-faint underline-offset-2 break-words">{{ $r->localizedSourceText() }}</a>
            </td>
            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $kindBadge($r->kind) }}">{{ $r->kind ?? '—' }}</span></td>
            <td class="px-4 py-3 font-mono whitespace-nowrap">{{ $r->final_code ?? '—' }}</td>
            <td class="px-4 py-3 text-muted max-w-[300px]">{{ \Illuminate\Support\Str::limit($codeName($r), 80) ?: '—' }}</td>
            <td class="px-4 py-3 whitespace-nowrap">
              @if($src)
                <span class="text-ledger">✓</span> <span class="text-muted">{{ __($src) }}</span>
              @else
                <span class="text-faint">—</span>
              @endif
            </td>
            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-md text-xs font-medium whitespace-nowrap {{ $statusBadge($r->resolution) }}">{{ __(str_replace('_', ' ', (string) $r->resolution)) }}</span></td>
          </tr>
        @empty
          <tr><td colspan="6" class="px-4 py-10 text-center text-muted">{{ __('Nothing here. Classify some items first.') }}</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
