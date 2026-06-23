<section class="p-5 sm:p-8">
  @php $kindBadge = fn ($k) => $k === 'service' ? 'bg-amber/15 text-amber' : 'bg-ledger/12 text-ledger'; @endphp

  <div class="flex items-end justify-between flex-wrap gap-3 mb-6">
    <div>
      <p class="kicker mb-1.5">{{ number_format($rows->total(),0,'.',' ') }} codes · XİF MN</p>
      <h1 class="font-display text-4xl">Classifier catalog</h1>
    </div>
    <div class="flex items-center gap-2">
      <div class="flex bg-surface border hair rounded-xl overflow-hidden text-sm">
        @foreach(['all'=>'All','good'=>'Goods','service'=>'Services'] as $k => $label)
          <button wire:click="$set('kind','{{ $k }}')" class="px-3 h-10 {{ $kind===$k ? 'bg-ink text-paper' : 'hover:bg-paper/60' }}">{{ $label }}</button>
        @endforeach
      </div>
      <div class="flex items-center gap-2 bg-surface border hair rounded-xl px-3.5 h-10 w-full sm:w-72">
        <span class="text-faint">⌕</span>
        <input wire:model.live.debounce.400ms="q" placeholder="Code or description…" class="w-full bg-transparent outline-none text-sm">
      </div>
    </div>
  </div>

  <div class="card-flat overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-muted border-b hair bg-paper/50">
            <th class="font-medium px-4 py-3">Code</th>
            <th class="font-medium px-4 py-3">Kind</th>
            <th class="font-medium px-4 py-3">Description</th>
            <th class="font-medium px-4 py-3">Unit</th>
          </tr>
        </thead>
        <tbody>
          @forelse($rows as $row)
            <tr wire:key="cat-{{ $row->id }}" class="border-b hair last:border-0 hover:bg-paper/40">
              <td class="px-4 py-3 font-mono whitespace-nowrap">{{ $row->code }}</td>
              <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $kindBadge($row->kind) }}">{{ $row->kind }}</span></td>
              <td class="px-4 py-3 text-muted max-w-[640px]">{{ Str::limit($row->name, 140) }}</td>
              <td class="px-4 py-3 text-faint whitespace-nowrap">{{ $row->unit ?? '—' }}</td>
            </tr>
          @empty
            <tr><td colspan="4" class="px-4 py-10 text-center text-muted">No codes match “{{ $q }}”.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-5">{{ $rows->onEachSide(1)->links() }}</div>
</section>
