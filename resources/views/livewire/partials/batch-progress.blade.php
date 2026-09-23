{{-- Live progress of one classification batch — shared by the Classify page and invoice
     uploads. Expects: $progress (BatchProgress::for), $queued (batch/count/total/label),
     $batchStats (BatchStats::for or null), $headingNames, $moreLabel (the "start over" button —
     null hides it; the host must then define startOver()) and $from (decision-page back link). --}}
@php $pct = $progress['count'] ? min(100, (int) round($progress['done'] / $progress['count'] * 100)) : 0; @endphp
<div class="card p-5 mt-6" @if(!$progress['complete']) wire:poll.1500ms @endif>
  <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
    <div class="flex items-center gap-2.5">
      @if($progress['complete'])
        <span class="w-7 h-7 grid place-items-center rounded-full bg-ledger/15 text-ledger">✓</span>
        <div>
          <p class="font-medium">{{ __('Classified :n items', ['n' => number_format($progress['count'])]) }}</p>
          <p class="text-muted text-sm">{{ $queued['label'] }}</p>
        </div>
      @else
        <span class="w-7 h-7 grid place-items-center">
          <span class="inline-block w-4 h-4 border-2 border-ink/25 border-t-ink rounded-full animate-spin"></span>
        </span>
        <div>
          <p class="font-medium tnum">{{ __('Classifying… :done / :count', ['done' => number_format($progress['done']), 'count' => number_format($progress['count'])]) }}</p>
          <p class="text-muted text-sm">{{ $queued['label'] }} · {{ __('runs in the background — you can leave this page.') }}</p>
        </div>
      @endif
    </div>
    <div class="flex gap-2">
      <a href="{{ route('review.batch', ['batch' => $queued['batch'], 'filter' => 'all']) }}" class="btn btn-ghost btn-sm">{{ __('Open in review →') }}</a>
      @if($moreLabel)
        <button wire:click="startOver" class="btn btn-ink btn-sm">{{ $moreLabel }}</button>
      @endif
    </div>
  </div>

  <div class="h-2 rounded-full bg-line/40 overflow-hidden">
    <div class="h-full bg-ledger transition-all duration-500" style="width: {{ $pct }}%"></div>
  </div>
  <p class="text-faint text-xs mt-1.5 tnum">
    {{ $pct }}%@if(($queued['total'] ?? 0) > $queued['count']) · {{ __('file had :total rows, first :count queued', ['total' => number_format($queued['total']), 'count' => number_format($queued['count'])]) }} @endif
  </p>

  @if($progress['rows']->isNotEmpty())
    <div class="mt-4">
      @include('livewire.partials.results-table', ['rows' => $progress['rows'], 'headingNames' => $headingNames, 'from' => $from])
    </div>
    @if($progress['done'] > $progress['rows']->count())
      <p class="text-faint text-xs mt-2">{{ __('Showing the latest :shown of :total.', ['shown' => $progress['rows']->count(), 'total' => number_format($progress['done'])]) }}</p>
    @endif
  @else
    <p class="text-muted text-sm mt-4">{{ __('Waiting for the first results…') }}</p>
  @endif
</div>

{{-- The per-batch funnel: which step resolved how many, and the recognition time.
     Re-rendered on the progress poll above, so it fills in live. --}}
@if($batchStats)
  <div class="mt-6">
    @include('livewire.partials.batch-stats', ['stats' => $batchStats])
  </div>
@endif
