<section class="p-5 sm:p-8 max-w-[1080px]">
  <div class="mb-5">
    <a href="{{ route('testing') }}" class="text-sm text-muted hover:underline">← {{ __('Testing') }}</a>
    <h1 class="font-display text-3xl mt-1">{{ $dataset->name }}</h1>
    <p class="text-sm text-muted mt-1">
      {{ __(':scorable of :total rows scorable', ['scorable' => $scorable, 'total' => $rows->total()]) }}
    </p>
  </div>

  {{-- Launch a run --}}
  <div class="card p-6 mb-6">
    <p class="font-medium mb-3">{{ __('New run') }}</p>
    <label class="field-label">{{ __('Description — what changed since last time?') }}</label>
    <input wire:model="description" class="field-input" placeholder="{{ __('e.g. baseline, or: heading-fusion on') }}">
    @error('description') <p class="text-sm text-stamp mt-1">{{ $message }}</p> @enderror
    <div class="mt-4 flex flex-wrap items-center gap-4">
      <span class="kicker">{{ __('Mechanisms') }}</span>
      @foreach([['useVector', __('Vector')], ['useBroker', __('Broker')], ['useDirect', __('Direct')], ['useSearch', __('Web search')], ['useMemory', __('Memory')]] as [$prop, $label])
        <label class="flex items-center gap-1.5 text-sm">
          {{-- Memory is .live so ticking it reveals the memory panel below --}}
          <input type="checkbox" wire:model{{ $prop === 'useMemory' ? '.live' : '' }}="{{ $prop }}"> {{ $label }}
        </label>
      @endforeach
      <button wire:click="launch" wire:loading.attr="disabled" wire:target="launch" class="btn btn-ink btn-sm ml-auto">
        <span wire:loading.remove wire:target="launch">{{ __('Run dataset →') }}</span>
        <span wire:loading wire:target="launch">{{ __('Starting…') }}</span>
      </button>
    </div>
    <p class="text-xs text-faint mt-3">{{ __('The effective models + retrieval flags are snapshotted at launch, so a later comparison reflects the code change, not config drift.') }}</p>
  </div>

  {{-- Dataset memory — shown only when the Memory mechanism is ticked for a run --}}
  @if($useMemory)
  <div class="card p-6 mb-6">
    <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
      <p class="font-medium">{{ __('Memory') }} <span class="text-muted font-normal">· {{ $memoryCount }} {{ __('entries') }}</span></p>
      @if($memoryCount > 0)
        <button wire:click="clearMemory" class="btn btn-ghost btn-sm">{{ __('Clear memory') }}</button>
      @endif
    </div>
    <p class="text-sm text-muted mb-3 max-w-[72ch]">{{ __('Memory is bound to THIS dataset only — production never sees it. Tick the Memory mechanism on a run to use it; a hit short-circuits the pipeline, exactly like production.') }}</p>
    <div class="flex flex-wrap items-center gap-2">
      <button wire:click="seedMemoryFromLabels" wire:loading.attr="disabled" wire:target="seedMemoryFromLabels" class="btn btn-ghost btn-sm">{{ __('Seed from correct answers') }}</button>
      @if($doneRuns->isNotEmpty())
        <span class="text-muted text-sm ml-1">{{ __('or from a run:') }}</span>
        <select wire:model="seedRunId" class="field-input py-1 h-9 w-auto">
          <option value="">{{ __('choose a run…') }}</option>
          @foreach($doneRuns as $r)<option value="{{ $r->id }}">#{{ $r->id }} · {{ Str::limit($r->description, 22) }}</option>@endforeach
        </select>
        <button wire:click="seedMemoryFromRun" class="btn btn-ghost btn-sm">{{ __('Seed') }}</button>
      @endif
    </div>
    <p class="text-xs text-faint mt-3">{{ __('“Correct answers” is the perfect-memory ceiling (leakage — exact-name rows then score ~100%). “From a run” replays what the pipeline produced (the flywheel).') }}</p>
  </div>
  @endif

  {{-- Runs --}}
  <div class="flex items-center justify-between mb-2">
    <p class="font-medium">{{ __('Runs') }}</p>
    @if($runs->count() >= 2)
      <form method="GET" action="{{ route('testing.compare') }}" class="flex items-center gap-2 text-sm">
        <select name="a" class="field-input py-1 h-9 w-auto">@foreach($runs as $r)<option value="{{ $r->id }}">#{{ $r->id }} · {{ Str::limit($r->description, 22) }}</option>@endforeach</select>
        <span class="text-muted">{{ __('vs') }}</span>
        <select name="b" class="field-input py-1 h-9 w-auto">@foreach($runs as $r)<option value="{{ $r->id }}">#{{ $r->id }} · {{ Str::limit($r->description, 22) }}</option>@endforeach</select>
        <button class="btn btn-ghost btn-sm">{{ __('Compare') }}</button>
      </form>
    @endif
  </div>
  <div class="card p-0 overflow-hidden mb-6">
    <table class="w-full text-sm">
      <thead class="text-muted text-left">
        <tr class="border-b hair">
          <th class="px-4 py-3 font-medium">{{ __('Run') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Overall') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Duration') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Tokens') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('When') }}</th>
        </tr>
      </thead>
      <tbody>
        @forelse($runs as $r)
          @php
            $o = $r->accuracy['columns']['overall'] ?? null;
            $acc = ($o && ($o['ran'] ?? 0) > 0) ? round(100 * $o['correct'] / $o['ran']) : null;
            $tok = $r->accuracy['tokens'] ?? null;
            $durS = ($r->started_at && $r->finished_at) ? $r->finished_at->diffInSeconds($r->started_at) : null;
            $dur = $durS === null ? '—' : (intdiv($durS, 60) > 0 ? intdiv($durS, 60).'m '.($durS % 60).'s' : $durS.'s');
          @endphp
          <tr class="border-b hair hover:bg-surface">
            <td class="px-4 py-3"><a href="{{ route('testing.run', $r) }}" class="hover:underline">#{{ $r->id }} · {{ $r->description }}</a></td>
            <td class="px-4 py-3"><span class="text-xs px-2 py-0.5 rounded-full {{ $r->status === 'done' ? 'bg-ledger/12 text-ledger' : 'bg-line/40 text-muted' }}">{{ __(ucfirst($r->status)) }}</span></td>
            <td class="px-4 py-3 tnum font-medium">{{ $acc !== null ? $acc.'%' : '—' }}</td>
            <td class="px-4 py-3 tnum text-muted">{{ $dur }}</td>
            <td class="px-4 py-3 tnum text-muted">{{ $tok !== null ? number_format($tok, 0, '.', ' ') : '—' }}</td>
            <td class="px-4 py-3 text-muted">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="px-4 py-6 text-center text-muted">{{ __('No runs yet — launch one above.') }}</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- Rows --}}
  <p class="font-medium mb-2">{{ __('Rows') }}</p>
  <div class="card p-0 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="text-muted text-left">
        <tr class="border-b hair">
          <th class="px-4 py-3 font-medium">{{ __('Item') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Expected code') }}</th>
          <th class="px-4 py-3 font-medium">{{ __('Heading') }}</th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $row)
          <tr class="border-b hair {{ $row->skip_reason ? 'opacity-50' : '' }}">
            <td class="px-4 py-3">{{ $row->source_text }}</td>
            <td class="px-4 py-3 font-mono">{{ $row->expected_code ?? '—' }}</td>
            <td class="px-4 py-3">
              @if($row->skip_reason)
                <span class="text-xs text-stamp" title="{{ $row->skip_reason }}">{{ __('skipped') }}</span>
              @else
                <span class="font-mono">{{ $row->expected_is_service ? 'SVC' : $row->expected_heading }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <div class="mt-4">{{ $rows->onEachSide(1)->links() }}</div>
</section>
