<section class="p-5 sm:p-8 max-w-[1080px]">
  @php
    $kindBadge = fn ($k) => match ($k) {
        'good' => 'bg-ledger/12 text-ledger',
        'service' => 'bg-amber/15 text-amber',
        default => 'bg-line/40 text-muted',
    };
    $statusBadge = fn ($s) => match ($s) {
        'auto_confirmed' => 'bg-ledger/12 text-ledger',
        'needs_review' => 'bg-amber/15 text-amber',
        'no_match' => 'bg-line/40 text-muted',
        'error' => 'bg-stamp/12 text-stamp',
        default => 'bg-line/40 text-muted',
    };
  @endphp

  <div class="flex items-end justify-between flex-wrap gap-3 mb-6">
    <div>
      <p class="kicker mb-1.5">XİF MN · goods & services</p>
      <h1 class="font-display text-4xl">Classify</h1>
    </div>
  </div>

  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @foreach([['Classified',$stats['total']],['Auto-confirmed',$stats['auto']],['Needs review',$stats['review']],['Tokens used',number_format($stats['tokensAll'],0,'.',' ')]] as [$l,$v])
      <div class="card-flat p-4"><p class="kicker mb-1.5">{{ $l }}</p><p class="font-display text-2xl tnum">{{ $v }}</p></div>
    @endforeach
  </div>

  <div class="card p-6">
    <label class="field-label">Items — one per line (max 20)</label>
    <textarea wire:model="input" rows="4" placeholder="e.g. Şpris 5ml 23G rezin porşenli"
              class="field-input font-mono text-sm" style="height:auto"></textarea>
    <div class="flex flex-wrap items-center gap-2 mt-3">
      @foreach($examples as $ex)
        <button type="button" wire:click="useExample(@js($ex))" class="btn btn-ghost btn-sm">+ {{ Str::limit($ex, 32) }}</button>
      @endforeach
      <button wire:click="run" wire:loading.attr="disabled" wire:target="run" class="btn btn-ink btn-sm ml-auto">
        <span wire:loading.remove wire:target="run">Match items →</span>
        <span wire:loading wire:target="run">Queuing…</span>
      </button>
    </div>
  </div>

  {{-- File upload — batch classification in the background --}}
  <div class="card-flat p-5 mt-4">
    <div class="flex items-center justify-between flex-wrap gap-3">
      <div>
        <p class="font-medium">Or upload a file</p>
        <p class="text-muted text-sm">.xlsx / .xls / .csv — one item name per row. Classified in the background (first {{ 200 }} rows).</p>
      </div>
      <div class="flex items-center gap-2">
        <input type="file" wire:model="file" accept=".xlsx,.xls,.csv" class="text-sm max-w-[230px]">
        <button wire:click="classifyFile" wire:loading.attr="disabled" wire:target="classifyFile,file" class="btn btn-ink btn-sm">
          <span wire:loading.remove wire:target="classifyFile,file">Queue file →</span>
          <span wire:loading wire:target="classifyFile,file">Queuing…</span>
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
              <p class="font-medium">Classified {{ number_format($progress['count']) }} items</p>
              <p class="text-muted text-sm">{{ $queued['label'] }}</p>
            </div>
          @else
            <span class="w-7 h-7 grid place-items-center">
              <span class="inline-block w-4 h-4 border-2 border-ink/25 border-t-ink rounded-full animate-spin"></span>
            </span>
            <div>
              <p class="font-medium tnum">Classifying… {{ number_format($progress['done']) }} / {{ number_format($progress['count']) }}</p>
              <p class="text-muted text-sm">{{ $queued['label'] }} · runs in the background — you can leave this page.</p>
            </div>
          @endif
        </div>
        <div class="flex gap-2">
          <a href="{{ route('review', ['batch' => $queued['batch'], 'filter' => 'all']) }}" class="btn btn-ghost btn-sm">Open in review →</a>
          <button wire:click="startOver" class="btn btn-ink btn-sm">Classify more</button>
        </div>
      </div>

      <div class="h-2 rounded-full bg-line/40 overflow-hidden">
        <div class="h-full bg-ledger transition-all duration-500" style="width: {{ $pct }}%"></div>
      </div>
      <p class="text-faint text-xs mt-1.5 tnum">
        {{ $pct }}%@if(($queued['total'] ?? 0) > $queued['count']) · file had {{ number_format($queued['total']) }} rows, first {{ number_format($queued['count']) }} queued @endif
      </p>

      @if($progress['rows']->isNotEmpty())
        <div class="card-flat overflow-hidden mt-4">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-muted border-b hair bg-paper/50">
                  <th class="font-medium px-4 py-3">Item</th>
                  <th class="font-medium px-4 py-3">Kind</th>
                  <th class="font-medium px-4 py-3">Code</th>
                  <th class="font-medium px-4 py-3">Matched name</th>
                  <th class="font-medium px-4 py-3 text-right">Conf.</th>
                  <th class="font-medium px-4 py-3">Status</th>
                </tr>
              </thead>
              <tbody>
                @foreach($progress['rows'] as $r)
                  <tr wire:key="res-{{ $r->id }}" class="border-b hair last:border-0 align-top">
                    <td class="px-4 py-3 max-w-[220px]">{{ $r->source_text }}</td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $kindBadge($r->kind) }}">{{ $r->kind ?? '—' }}</span></td>
                    <td class="px-4 py-3 font-mono whitespace-nowrap">{{ $r->matched_code ?? '—' }}</td>
                    <td class="px-4 py-3 text-muted max-w-[320px]">{{ Str::limit(optional($r->code)->name ?? ($r->explanation ?? '—'), 90) }}</td>
                    <td class="px-4 py-3 tnum text-right">{{ $r->confidence !== null ? number_format($r->confidence*100,0).'%' : '—' }}</td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-md text-xs font-medium whitespace-nowrap {{ $statusBadge($r->status) }}">{{ str_replace('_',' ',$r->status) }}</span></td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
        @if($progress['done'] > $progress['rows']->count())
          <p class="text-faint text-xs mt-2">Showing the latest {{ $progress['rows']->count() }} of {{ number_format($progress['done']) }}.</p>
        @endif
      @else
        <p class="text-muted text-sm mt-4">Waiting for the first results…</p>
      @endif
    </div>
  @endif
</section>
