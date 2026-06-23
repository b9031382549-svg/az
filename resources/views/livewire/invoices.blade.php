<section class="p-5 sm:p-8">
  <div class="flex items-end justify-between flex-wrap gap-3 mb-6">
    <div>
      <p class="kicker mb-1.5">{{ number_format($invoices->total(), 0, '.', ' ') }} records</p>
      <h1 class="font-display text-4xl">Invoices</h1>
    </div>
    <div class="flex items-center gap-2 bg-surface border hair rounded-xl px-3.5 h-10 w-full sm:w-80">
      <span class="text-faint">⌕</span>
      <input wire:model.live.debounce.400ms="q" placeholder="Search TIN, series, number…"
             class="w-full bg-transparent outline-none text-sm">
      @if($q !== '')
        <button wire:click="clear" class="text-faint hover:text-ink">✕</button>
      @endif
    </div>
  </div>

  <div class="card-flat overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-muted border-b hair bg-paper/50">
            <th class="font-medium px-4 py-3">Date</th>
            <th class="font-medium px-4 py-3">Series · No.</th>
            <th class="font-medium px-4 py-3">Supplier</th>
            <th class="font-medium px-4 py-3">Recipient</th>
            <th class="font-medium px-4 py-3 text-right">VAT</th>
            <th class="font-medium px-4 py-3 text-right">Total</th>
          </tr>
        </thead>
        <tbody>
          @forelse($invoices as $inv)
            <tr wire:key="inv-{{ $inv->id }}" class="border-b hair last:border-0 hover:bg-paper/40 transition">
              <td class="px-4 py-3 tnum whitespace-nowrap">{{ $inv->invoice_date->format('d.m.Y') }}</td>
              <td class="px-4 py-3 font-mono whitespace-nowrap">{{ $inv->series }}·{{ $inv->number }}</td>
              <td class="px-4 py-3 font-mono">{{ $inv->supplier_tin }}</td>
              <td class="px-4 py-3 font-mono">{{ $inv->recipient_tin }}</td>
              <td class="px-4 py-3 tnum text-right whitespace-nowrap">{{ number_format($inv->vat_amount, 2, '.', ' ') }}</td>
              <td class="px-4 py-3 tnum text-right whitespace-nowrap font-medium">{{ number_format($inv->total_amount, 2, '.', ' ') }}</td>
            </tr>
          @empty
            <tr><td colspan="6" class="px-4 py-10 text-center text-muted">No invoices match “{{ $q }}”.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-5">
    {{ $invoices->onEachSide(1)->links() }}
  </div>
</section>
