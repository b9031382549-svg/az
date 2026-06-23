<section class="p-5 sm:p-8 max-w-[1080px]">
  @php
    $tabs = ['needs_review' => 'Needs review', 'auto_confirmed' => 'Auto-confirmed', 'confirmed' => 'Confirmed', 'rejected' => 'Rejected', 'all' => 'All'];
    $kindBadge = fn ($k) => $k === 'service' ? 'bg-amber/15 text-amber' : ($k === 'good' ? 'bg-ledger/12 text-ledger' : 'bg-line/40 text-muted');
  @endphp

  <div class="mb-6">
    <p class="kicker mb-1.5">Quality control</p>
    <h1 class="font-display text-4xl">Review queue</h1>
  </div>

  <div class="flex flex-wrap gap-2 mb-5">
    @foreach($tabs as $key => $label)
      <button wire:click="setFilter('{{ $key }}')"
              class="px-3 py-1.5 rounded-lg text-sm border hair transition {{ $filter === $key ? 'bg-ink text-paper border-ink' : 'bg-surface hover:border-ink' }}">
        {{ $label }}
        <span class="opacity-60">{{ $key === 'all' ? $counts->sum() : ($counts[$key] ?? 0) }}</span>
      </button>
    @endforeach
  </div>

  <div class="space-y-3">
    @forelse($items as $item)
      <div wire:key="cls-{{ $item->id }}" class="card-flat p-4 flex items-start gap-4">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 mb-1">
            <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $kindBadge($item->kind) }}">{{ $item->kind ?? '—' }}</span>
            <span class="font-mono text-sm">{{ $item->matched_code ?? 'no match' }}</span>
            <span class="text-faint text-xs tnum">{{ $item->confidence !== null ? number_format($item->confidence*100,0).'%' : '' }}</span>
          </div>
          <p class="font-medium">{{ $item->source_text }}</p>
          @if($item->code)
            <p class="text-muted text-sm mt-0.5">{{ Str::limit($item->code->name, 110) }}</p>
          @endif
          @if($item->explanation)
            <p class="text-faint text-xs mt-1">{{ Str::limit($item->explanation, 130) }}</p>
          @endif
        </div>
        <div class="flex items-center gap-2 shrink-0">
          @if(in_array($item->status, ['needs_review','auto_confirmed']))
            <button wire:click="confirm({{ $item->id }})" class="btn btn-ghost btn-sm">✓ Confirm</button>
            <button wire:click="reject({{ $item->id }})" class="btn btn-ghost btn-sm">✕ Reject</button>
          @else
            <span class="text-xs text-faint">{{ str_replace('_',' ',$item->status) }}</span>
          @endif
        </div>
      </div>
    @empty
      <div class="card-flat p-10 text-center text-muted">Nothing here. Classify some items first.</div>
    @endforelse
  </div>

  <div class="mt-5">{{ $items->onEachSide(1)->links() }}</div>
</section>
