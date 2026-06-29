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
        <span wire:loading wire:target="run">Matching…</span>
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
    @if($queued)
      <div class="mt-3 card p-3.5 text-sm border-ledger/40 flex items-start gap-2.5">
        <span class="text-ledger">✓</span>
        <span>Queued <b>{{ number_format($queued['count'],0,'.',' ') }}</b>@if($queued['total'] > $queued['count']) of {{ number_format($queued['total'],0,'.',' ') }}@endif items for background classification.
          <a href="{{ route('review', ['batch' => $queued['batch'], 'filter' => 'all']) }}" class="link-under text-ink font-medium">Open this upload in the queue →</a>
          <span class="text-muted">Items appear as the worker processes them.</span></span>
      </div>
    @endif
  </div>

  @if(!empty($results))
    <div class="flex items-center justify-between mt-7 mb-3">
      <h2 class="font-display text-xl">{{ count($results) }} items matched</h2>
      @if($tokens)<span class="kicker">{{ number_format($tokens,0,'.',' ') }} tokens</span>@endif
    </div>
    <div class="card-flat overflow-hidden">
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
            @foreach($results as $r)
              <tr class="border-b hair last:border-0 align-top">
                <td class="px-4 py-3 max-w-[220px]">{{ $r['text'] }}</td>
                <td class="px-4 py-3">
                  <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $kindBadge($r['kind']) }}">{{ $r['kind'] ?? '—' }}</span>
                </td>
                <td class="px-4 py-3 font-mono whitespace-nowrap">{{ $r['code'] ?? '—' }}</td>
                <td class="px-4 py-3 text-muted max-w-[320px]">{{ Str::limit($r['name'] ?? ($r['reason'] ?? '—'), 90) }}</td>
                <td class="px-4 py-3 tnum text-right">{{ $r['confidence'] !== null ? number_format($r['confidence']*100,0).'%' : '—' }}</td>
                <td class="px-4 py-3">
                  <span class="px-2 py-0.5 rounded-md text-xs font-medium whitespace-nowrap {{ $statusBadge($r['status']) }}">{{ str_replace('_',' ',$r['status']) }}</span>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
    <p class="text-faint text-sm mt-3">Saved to the review queue.
      <a href="{{ route('review', ['batch' => $lastBatch, 'filter' => 'all']) }}" class="link-under text-ink">Open this upload in the queue →</a></p>
  @endif
</section>
