<section class="p-5 sm:p-8 max-w-[1080px]" wire:poll.3s="pollState">
  @php
    $spinner = '<svg class="animate-spin w-3.5 h-3.5" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" stroke-opacity="0.25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>';
  @endphp
  <div class="mb-5 flex items-end justify-between flex-wrap gap-3">
    <div>
      <p class="kicker mb-1.5">{{ __('Inference infrastructure') }}</p>
      <h1 class="font-display text-4xl">{{ __('GPU servers') }}</h1>
    </div>
    <div class="flex items-center gap-3">
      @if($working)
        <span class="inline-flex items-center gap-1.5 text-xs text-ledger">
          {!! $spinner !!} {{ __('Auto-updating…') }}
        </span>
      @endif
      <button wire:click="tick" class="btn btn-ghost btn-sm text-muted" title="{{ __('Advance the state machine one step') }}">
        {{ __('Refresh state') }} ↻
      </button>
    </div>
  </div>

  <p class="text-sm text-muted mb-5 max-w-[76ch]">
    {{ __('Two GPU slots in a weekly zero-downtime retrain loop: one serves code-search while the other trains, then the freshly-trained one becomes active and the old is torn down — the search endpoint never pauses. The whole system (classifier + tests) routes "gpu:" models here.') }}
  </p>

  @error('gpu') <div class="card-flat p-3 mb-4 text-sm text-stamp">{{ $message }}</div> @enderror

  {{-- Where traffic goes right now --}}
  @if($active && $active->isServing())
    <div class="card-flat p-3 mb-5 text-sm flex items-center gap-2 flex-wrap">
      <span class="w-2 h-2 rounded-full bg-ledger inline-block"></span>
      {{ __('Code-search is served by') }} <strong>{{ __('Slot') }} {{ $active->slot }}</strong>
      @if($active->adapter) <span class="text-muted">· {{ __('adapter') }} {{ $active->adapter->version }}</span> @endif
    </div>
  @else
    <div class="card-flat p-3 mb-5 text-sm flex items-center gap-2 flex-wrap">
      <span class="w-2 h-2 rounded-full bg-stamp inline-block"></span>
      {{ __('No GPU server active — processing runs through Token Factory (base model, NOT fine-tuned).') }}
    </div>
  @endif

  @unless($goldenReady)
    <div class="card-flat p-3 mb-5 text-xs text-muted">
      {{ __('No golden snapshot configured (NEBIUS_GOLDEN_SNAPSHOT_ID). Provisioning is disabled until the snapshot is baked — the paid step.') }}
    </div>
  @endunless

  {{-- The two slots --}}
  <div class="grid sm:grid-cols-2 gap-4 mb-6">
    @foreach($servers as $s)
      @php
        $badge = match($s->status) {
          'serving' => 'bg-ledger/15 text-ledger',
          'training' => 'bg-stamp/15 text-stamp',
          'error' => 'bg-stamp/15 text-stamp',
          'off' => 'bg-surface text-muted',
          default => 'bg-ledger/10 text-ledger',
        };
        $busy = ! in_array($s->status, ['off', 'error'], true);
        $transient = in_array($s->status, ['provisioning', 'booting', 'deploying_adapter', 'training', 'uploading_adapter'], true);
      @endphp
      <div class="card p-5 @if($s->is_active) ring-1 ring-ledger @endif">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-2">
            <span class="font-display text-2xl">{{ __('Slot') }} {{ $s->slot }}</span>
            @if($s->is_active)<span class="text-xs px-2 py-0.5 rounded-full bg-ledger text-paper">{{ __('ACTIVE') }}</span>@endif
          </div>
          <span class="text-xs px-2 py-0.5 rounded-full {{ $badge }} inline-flex items-center gap-1">
            @if($transient) {!! $spinner !!} @endif{{ __($s->statusLabel()) }}
          </span>
        </div>

        <dl class="text-sm space-y-1 mb-4">
          <div class="flex justify-between"><dt class="text-muted">{{ __('Role') }}</dt><dd>{{ $s->role ?? '—' }}</dd></div>
          <div class="flex justify-between"><dt class="text-muted">{{ __('IP') }}</dt><dd class="tnum">{{ $s->ip ?? '—' }}</dd></div>
          <div class="flex justify-between"><dt class="text-muted">{{ __('Model') }}</dt><dd>{{ $s->servedModel() ?? '—' }}</dd></div>
        </dl>

        @if($s->status_detail)
          <p class="text-xs text-muted mb-4 leading-snug">{{ $s->status_detail }}</p>
        @endif

        {{-- Contextual actions --}}
        <div class="flex flex-wrap gap-2">
          @if($s->status === 'off')
            <button wire:click="serveBase({{ $s->id }})" @disabled(! $goldenReady)
                    wire:loading.attr="disabled" wire:target="serveBase({{ $s->id }})"
                    wire:confirm="{{ __('Rent a GPU and serve the base model here? (H200 ≈ $4.5/hr, auto-stops after 2h idle)') }}"
                    class="btn btn-ink btn-sm">
              <span wire:loading.remove wire:target="serveBase({{ $s->id }})">{{ __('Turn on (base) →') }}</span>
              <span wire:loading wire:target="serveBase({{ $s->id }})">{{ __('Starting…') }}</span>
            </button>
            <button wire:click="startFinetune({{ $s->id }})" @disabled(! $goldenReady)
                    wire:loading.attr="disabled" wire:target="startFinetune({{ $s->id }})"
                    wire:confirm="{{ __('Rent a GPU and start a training run here?') }}"
                    class="btn btn-ghost btn-sm">{{ __('Start training') }}</button>
            @if($adapters->isNotEmpty())
              <div class="flex items-center gap-1.5">
                <select wire:model="serveAdapter.{{ $s->id }}" class="field-input text-xs py-1 h-8 max-w-[150px]">
                  <option value="">{{ __('adapter…') }}</option>
                  @foreach($adapters as $a)<option value="{{ $a->id }}">{{ $a->version }}</option>@endforeach
                </select>
                <button wire:click="provisionServing({{ $s->id }})" @disabled(! $goldenReady) class="btn btn-ghost btn-sm">{{ __('Serve') }}</button>
              </div>
            @endif
          @endif

          @if($s->status === 'ready_to_switch' || ($s->status === 'serving' && ! $s->is_active))
            <button wire:click="switchTo({{ $s->id }})"
                    wire:confirm="{{ __('Switch code-search to this slot and destroy the current one?') }}"
                    class="btn btn-ink btn-sm">{{ __('Switch here →') }}</button>
          @endif

          @if($busy || $s->status === 'error')
            <button wire:click="destroyServer({{ $s->id }})"
                    wire:confirm="{{ __('Destroy this slot?') }}"
                    class="btn btn-ghost btn-sm text-stamp">{{ __('Destroy') }}</button>
          @endif
        </div>
      </div>
    @endforeach
  </div>

  {{-- Training runs --}}
  <div class="card p-0 overflow-hidden">
    <div class="px-4 py-3 border-b hair"><p class="font-medium">{{ __('Retrain history') }}</p></div>
    <table class="w-full text-sm">
      <thead class="text-muted text-left">
        <tr class="border-b hair">
          <th class="px-4 py-2.5 font-medium">{{ __('Run') }}</th>
          <th class="px-4 py-2.5 font-medium">{{ __('Status') }}</th>
          <th class="px-4 py-2.5 font-medium">{{ __('Progress') }}</th>
          <th class="px-4 py-2.5 font-medium">{{ __('Adapter') }}</th>
          <th class="px-4 py-2.5 font-medium">{{ __('Eval (direct/overall)') }}</th>
          <th class="px-4 py-2.5 font-medium">{{ __('Started') }}</th>
        </tr>
      </thead>
      <tbody>
        @forelse($runs as $r)
          <tr class="border-b hair hover:bg-surface">
            <td class="px-4 py-2.5">#{{ $r->id }} <span class="text-muted">{{ __('slot') }} {{ $r->server?->slot ?? '—' }}</span></td>
            <td class="px-4 py-2.5">{{ $r->status }}</td>
            <td class="px-4 py-2.5">
              @if($r->status === 'training' && $r->progressPct() !== null)
                <div class="flex items-center gap-2">
                  <div class="w-24 h-1.5 rounded-full bg-surface overflow-hidden"><div class="h-full bg-ledger" style="width: {{ $r->progressPct() }}%"></div></div>
                  <span class="tnum text-xs text-muted">{{ $r->step }}/{{ $r->total_steps }}
                    @if($r->loss !== null)· loss {{ number_format($r->loss, 3) }}@endif
                    @if($r->eta_seconds)· ~{{ \Carbon\CarbonInterval::seconds($r->eta_seconds)->cascade()->forHumans(short: true) }}@endif
                  </span>
                </div>
              @elseif($r->corpus_rows)
                <span class="text-muted text-xs">{{ number_format($r->corpus_rows) }} {{ __('examples') }}</span>
              @else
                <span class="text-muted">—</span>
              @endif
            </td>
            <td class="px-4 py-2.5">{{ $r->adapter?->version ?? '—' }}</td>
            <td class="px-4 py-2.5 tnum">{{ $r->adapter?->direct_acc !== null ? $r->adapter->direct_acc.'% / '.$r->adapter->overall_acc.'%' : '—' }}</td>
            <td class="px-4 py-2.5 text-muted">{{ $r->started_at?->format('Y-m-d H:i') }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="px-4 py-6 text-center text-muted">{{ __('No retrain runs yet.') }}</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
