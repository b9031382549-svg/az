{{-- A trash verdict on the decision page — the rules / the sorter (stage ②, before the AI)
     or the web search (after the AI). Expects $item, $trashCheck, $trashRule, $pill, $stageNo. --}}
@php
  $overridden = $trashCheck->status === 'overridden';
  $bySearch = $trashRule === 'search';
  $reason = data_get($trashCheck->trace, 'reason');
  $p = data_get($trashCheck->trace, 'p');
@endphp
<li class="card p-5">
  <div class="flex items-center justify-between gap-3 mb-3">
    <div class="flex items-center gap-2.5">
      <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-line/40 text-xs font-semibold">{{ $stageNo }}</span>
      <span class="font-medium">{{ $bySearch ? __('Web search') : __('Trash filter') }}</span>
      <span class="text-faint text-xs">{{ __('does the name name a product at all?') }}</span>
    </div>
    <span class="hint-head px-2 py-0.5 rounded-md text-xs font-medium {{ $pill($overridden ? 'warn' : 'muted') }}" data-hint="{{ $overridden ? 'trash:overridden' : 'trash' }}">{{ $overridden ? __('overridden by a reviewer') : __('trash') }}</span>
  </div>
  <div class="flex flex-col sm:flex-row gap-2 text-sm">
    <div class="flex-1 rounded-lg border hair p-3 min-w-0">
      <p class="kicker mb-1">{{ __('Input') }}</p>
      <p class="break-words">{{ $item->source_text }}</p>
    </div>
    <div class="flex items-center justify-center text-faint">→</div>
    <div class="flex-1 rounded-lg border hair p-3 min-w-0">
      <p class="kicker mb-1">{{ __('Output') }}</p>
      <p>{{ \App\Services\Classify\TrashFilter::explain($trashRule) }}@if($trashRule === 'sorter' && $p !== null) <span class="text-muted">({{ __('trash') }} {{ number_format((float) $p * 100, 1) }}%)</span>@endif</p>
      @if($bySearch)
        <p class="text-muted text-xs mt-0.5">@if($reason){{ \App\Services\Classify\TrashFilter::reasonLabel((string) $reason) }} · @endif{{ __('Sorter') }}: {{ __('trash') }} {{ number_format((float) $p * 100, 1) }}%</p>
      @endif
      <p class="text-xs mt-0.5 {{ $overridden ? 'text-amber' : 'text-muted' }}">{{ $overridden ? __('a reviewer said it is a product → sent to the AI') : ($bySearch ? __('no code was forced on it') : __('not classified — no AI was run')) }}</p>
    </div>
  </div>
  @if(! $overridden && $item->resolution === 'trash' && \App\Services\Classify\TrashFilter::isAbsolute($trashRule))
    <p class="mt-3 text-xs text-muted">{{ __('A name of only digits is always trash — it is never sent to the AI.') }}</p>
  @elseif(! $overridden && $item->resolution === 'trash')
    <div class="mt-3 flex items-center justify-between gap-3 flex-wrap">
      <p class="text-xs text-muted">{{ __('If this line does name a product or a service, send it to the AI.') }}</p>
      <button wire:click="classifyAnyway" wire:confirm="{{ __('Not trash — classify this item with the AI?') }}" class="btn btn-ghost btn-sm">↻ {{ __('Not trash — classify') }}</button>
    </div>
  @endif
</li>
