<section class="p-5 sm:p-8 max-w-[1080px]">
  @php
    $kindBadge = fn ($k) => match ($k) {
        'good' => 'bg-ledger/12 text-ledger',
        'service' => 'bg-amber/15 text-amber',
        default => 'bg-line/40 text-muted',
    };
    $statusBadge = fn ($s) => match ($s) {
        'agreed', 'confirmed', 'ai_resolved' => 'bg-ledger/12 text-ledger',
        'blocked_on_fact' => 'bg-amber/15 text-amber',
        'conflict' => 'bg-stamp/12 text-stamp',
        default => 'bg-line/40 text-muted', // no_match, rejected, pending
    };
  @endphp

  <div class="flex items-end justify-between flex-wrap gap-3 mb-6">
    <div>
      <p class="kicker mb-1.5">{{ __('XİF MN · goods & services') }}</p>
      <h1 class="font-display text-4xl">{{ __('Classify') }}</h1>
    </div>
  </div>

  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @foreach([[__('Classified'),$stats['total']],[__('Found'),$stats['auto']],[__('Needs attention'),$stats['review']],[__('Tokens used'),number_format($stats['tokensAll'],0,'.',' ')]] as [$l,$v])
      <div class="card-flat p-4"><p class="kicker mb-1.5">{{ $l }}</p><p class="font-display text-2xl tnum">{{ $v }}</p></div>
    @endforeach
  </div>

  <div class="card p-6">
    <label class="field-label">{{ __('Items — one per line (max :n)', ['n' => number_format($manualLimit)]) }}</label>
    <textarea wire:model="input" rows="4" placeholder="{{ __('e.g. Şpris 5ml 23G rezin porşenli') }}"
              class="field-input font-mono text-sm" style="height:auto"></textarea>
    <div class="flex flex-wrap items-center gap-2 mt-3">
      @foreach($examples as $ex)
        <button type="button" wire:click="useExample(@js($ex))" class="btn btn-ghost btn-sm">+ {{ Str::limit($ex, 32) }}</button>
      @endforeach
      <button wire:click="run" wire:loading.attr="disabled" wire:target="run" class="btn btn-ink btn-sm ml-auto">
        <span wire:loading.remove wire:target="run">{{ __('Match items →') }}</span>
        <span wire:loading wire:target="run">{{ __('Queuing…') }}</span>
      </button>
    </div>
  </div>

  {{-- File upload — batch classification in the background --}}
  <div class="card-flat p-5 mt-4">
    <div class="flex items-center justify-between flex-wrap gap-3">
      <div>
        <p class="font-medium">{{ __('Or upload a file') }}</p>
        <p class="text-muted text-sm">{{ __('.xlsx / .xls / .csv — one item name per row. Classified in the background (up to :n rows).', ['n' => number_format($fileLimit)]) }}</p>
      </div>
      <div class="flex items-center gap-2">
        <input type="file" wire:model="file" accept=".xlsx,.xls,.csv" class="text-sm max-w-[230px]">
        <button wire:click="classifyFile" wire:loading.attr="disabled" wire:target="classifyFile,file" class="btn btn-ink btn-sm">
          <span wire:loading.remove wire:target="classifyFile,file">{{ __('Queue file →') }}</span>
          <span wire:loading wire:target="classifyFile,file">{{ __('Queuing…') }}</span>
        </button>
      </div>
    </div>
    @error('file') <p class="text-sm text-stamp mt-2">{{ $message }}</p> @enderror
  </div>

  {{-- Live progress for the active upload --}}
  @if($progress)
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
          <a href="{{ route('review', ['batch' => $queued['batch'], 'filter' => 'all']) }}" class="btn btn-ghost btn-sm">{{ __('Open in review →') }}</a>
          <button wire:click="startOver" class="btn btn-ink btn-sm">{{ __('Classify more') }}</button>
        </div>
      </div>

      <div class="h-2 rounded-full bg-line/40 overflow-hidden">
        <div class="h-full bg-ledger transition-all duration-500" style="width: {{ $pct }}%"></div>
      </div>
      <p class="text-faint text-xs mt-1.5 tnum">
        {{ $pct }}%@if(($queued['total'] ?? 0) > $queued['count']) · {{ __('file had :total rows, first :count queued', ['total' => number_format($queued['total']), 'count' => number_format($queued['count'])]) }} @endif
      </p>

      @if($progress['rows']->isNotEmpty())
        <div class="card-flat overflow-hidden mt-4">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-muted border-b hair bg-paper/50">
                  <th class="font-medium px-4 py-3">{{ __('Item') }}</th>
                  <th class="font-medium px-4 py-3">{{ __('Kind') }}</th>
                  <th class="font-medium px-4 py-3">{{ __('Code') }}</th>
                  <th class="font-medium px-4 py-3">{{ __('Matched name') }}</th>
                  <th class="font-medium px-4 py-3 text-right">{{ __('Conf.') }}</th>
                  <th class="font-medium px-4 py-3">{{ __('Status') }}</th>
                </tr>
              </thead>
              <tbody>
                @foreach($progress['rows'] as $r)
                  <tr wire:key="res-{{ $r->id }}" class="border-b hair last:border-0 align-top">
                    <td class="px-4 py-3 max-w-[220px]">{{ $r->localizedSourceText() }}</td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $kindBadge($r->kind) }}">{{ $r->kind ?? '—' }}</span></td>
                    <td class="px-4 py-3 font-mono whitespace-nowrap">{{ $r->final_code ?? '—' }}</td>
                    <td class="px-4 py-3 text-muted max-w-[320px]">{{ Str::limit($r->finalCode?->localizedName() ?: ($headingNames[(string) $r->final_code] ?? '—'), 90) }}</td>
                    <td class="px-4 py-3 tnum text-right">{{ $r->finalConfidence() !== null ? number_format($r->finalConfidence()*100,0).'%' : '—' }}</td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-md text-xs font-medium whitespace-nowrap {{ $statusBadge($r->resolution) }}">{{ str_replace('_',' ',$r->resolution) }}</span></td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
        @if($progress['done'] > $progress['rows']->count())
          <p class="text-faint text-xs mt-2">{{ __('Showing the latest :shown of :total.', ['shown' => $progress['rows']->count(), 'total' => number_format($progress['done'])]) }}</p>
        @endif
      @else
        <p class="text-muted text-sm mt-4">{{ __('Waiting for the first results…') }}</p>
      @endif
    </div>
  @endif
</section>
