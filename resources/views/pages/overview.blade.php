<x-app-layout :title="'Overview · '.config('app.name')">
<section class="p-5 sm:p-8 max-w-[1180px]">
  <div class="flex items-end justify-between flex-wrap gap-3 mb-7">
    <div>
      <p class="kicker mb-1.5">Period · {{ $period }}</p>
      <h1 class="font-display text-4xl">Overview</h1>
    </div>
    <div class="flex gap-2">
      <a href="{{ route('upload') }}" class="btn btn-ghost btn-sm">⬆ Upload</a>
      <a href="{{ route('ask') }}" class="btn btn-stamp btn-sm">Ask AI</a>
    </div>
  </div>

  @php
    $kpis = [
      ['Turnover', '₼ '.number_format($agg->turnover, 0, '.', ' ')],
      ['VAT collected', '₼ '.number_format($agg->vat, 0, '.', ' ')],
      ['Invoices', number_format($agg->invoices, 0, '.', ' ')],
      ['Suppliers', number_format($agg->suppliers, 0, '.', ' ')],
    ];
  @endphp
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-7">
    @foreach($kpis as [$label, $value])
      <div class="card-flat p-5">
        <p class="kicker mb-2">{{ $label }}</p>
        <p class="font-display text-3xl tnum">{{ $value }}</p>
      </div>
    @endforeach
  </div>

  <div class="grid lg:grid-cols-5 gap-5">
    <div class="lg:col-span-3 card p-6">
      <div class="flex items-center justify-between mb-6">
        <h2 class="font-display text-xl">Turnover by month</h2>
        <span class="kicker">2026</span>
      </div>
      <div class="flex items-end gap-6 h-52">
        @forelse($months as $m)
          <div class="flex-1 h-full flex flex-col items-center justify-end gap-2">
            <span class="text-xs tnum text-muted">{{ number_format($m['total'] / 1000, 0) }}k</span>
            <div class="w-full bg-ink/85 rounded-t-lg transition-all" style="height: {{ $m['pct'] }}%"></div>
            <span class="kicker">{{ $m['label'] }}</span>
          </div>
        @empty
          <p class="text-muted">No data yet.</p>
        @endforelse
      </div>
    </div>

    <div class="lg:col-span-2 card p-6">
      <h2 class="font-display text-xl mb-5">Recent invoices</h2>
      <ul class="space-y-3.5 text-sm">
        @foreach($recent as $inv)
          <li class="flex items-center justify-between">
            <div class="leading-tight">
              <div class="font-medium font-mono">{{ $inv->series }}·{{ $inv->number }}</div>
              <div class="text-faint text-xs">{{ $inv->supplier_tin }} → {{ $inv->recipient_tin }}</div>
            </div>
            <div class="text-right leading-tight">
              <div class="tnum font-medium">₼ {{ number_format($inv->total_amount, 0, '.', ' ') }}</div>
              <div class="text-faint text-xs tnum">{{ $inv->invoice_date->format('d.m.Y') }}</div>
            </div>
          </li>
        @endforeach
      </ul>
      <a href="{{ route('invoices') }}" class="btn btn-ghost btn-sm w-full mt-5">All invoices →</a>
    </div>
  </div>
</section>
</x-app-layout>
