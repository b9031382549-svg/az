<section class="p-5 sm:p-8 max-w-[920px]">
  <div class="flex items-end justify-between flex-wrap gap-3 mb-6">
    <div>
      <p class="kicker mb-1.5">Data import</p>
      <h1 class="font-display text-4xl">Upload invoices</h1>
    </div>
    <div class="card-flat px-4 py-2.5 text-sm">
      <span class="text-muted">In the database now:</span>
      <span class="font-display text-lg tnum ml-1">{{ number_format($existing, 0, '.', ' ') }}</span>
      <span class="text-muted">invoices</span>
    </div>
  </div>

  @php
    $steps = [1 => 'File', 2 => 'Preview', 3 => 'Result'];
  @endphp
  <ol class="flex items-center gap-3 mb-7 text-sm">
    @foreach($steps as $n => $label)
      <li class="flex items-center gap-2 {{ $step >= $n ? '' : 'text-muted' }}">
        <span class="w-6 h-6 grid place-items-center rounded-md font-mono text-xs {{ $step >= $n ? 'bg-stamp text-paper' : 'border hair' }}">{{ $n }}</span>{{ $label }}
      </li>
      @if($n < 3)<li class="w-8 border-t hair"></li>@endif
    @endforeach
  </ol>

  {{-- STEP 1 — choose file --}}
  @if($step === 1)
    <label class="dropzone card bg-surface border-2 border-dashed hair p-12 text-center block cursor-pointer hover:border-ink transition">
      <input type="file" wire:model="file" class="hidden" accept=".xlsx,.xls,.csv">
      <div wire:loading.remove wire:target="file">
        <div class="text-4xl mb-3 text-faint">⬆</div>
        <p class="font-display text-2xl mb-1">Drag your file here</p>
        <p class="text-muted mb-5">or click to choose a file</p>
        <span class="btn btn-ink">Choose file</span>
        <p class="text-xs text-faint mt-5">Supported: .xlsx, .xls, .csv · up to 25&nbsp;MB</p>
      </div>
      <div wire:loading wire:target="file" class="py-6">
        <p class="font-display text-xl">Reading file…</p>
      </div>
    </label>
    @error('file') <p class="text-sm text-stamp mt-3">{{ $message }}</p> @enderror
    <div class="mt-4 flex items-start gap-2.5 text-sm text-muted card-flat p-3.5">
      <span class="text-amber">ℹ</span>
      <span>The file must have the 15 standard columns: No., supplier/recipient TIN, issue &amp; approval dates, series, number, the VAT amount columns and total.</span>
    </div>
    @if($existing > 0)
      <div class="mt-3 flex items-start gap-2.5 text-sm card-flat p-3.5 border-amber/40">
        <span class="text-amber">⚠</span>
        <span>The database already holds <b>{{ number_format($existing, 0, '.', ' ') }}</b> invoices. A new import is <b>added on top</b> unless you tick <b>“Replace existing data”</b> on the next step — re-importing the same file would create duplicates.</span>
      </div>
    @endif
  @endif

  {{-- STEP 2 — preview --}}
  @if($step === 2)
    <div class="card-flat p-5 mb-4">
      <div class="flex items-center justify-between flex-wrap gap-2">
        <span class="font-mono text-sm">{{ $file?->getClientOriginalName() }}</span>
        @if($preview['ok'])
          <span class="text-ledger text-sm">{{ number_format($preview['count'],0,'.',' ') }} rows found ✓</span>
        @else
          <span class="text-stamp text-sm">{{ $preview['error'] }}</span>
        @endif
      </div>
    </div>

    @if($preview['ok'])
      <div class="card-flat overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-muted border-b hair bg-paper/50">
                <th class="font-medium px-4 py-2.5">Date</th>
                <th class="font-medium px-4 py-2.5">Series·No.</th>
                <th class="font-medium px-4 py-2.5">Supplier</th>
                <th class="font-medium px-4 py-2.5">Recipient</th>
                <th class="font-medium px-4 py-2.5 text-right">VAT</th>
                <th class="font-medium px-4 py-2.5 text-right">Total</th>
              </tr>
            </thead>
            <tbody>
              @foreach($preview['sample'] as $r)
                <tr class="border-b hair last:border-0">
                  <td class="px-4 py-2.5 tnum whitespace-nowrap">{{ $r['invoice_date'] }}</td>
                  <td class="px-4 py-2.5 font-mono whitespace-nowrap">{{ $r['series'] }}·{{ $r['number'] }}</td>
                  <td class="px-4 py-2.5 font-mono">{{ $r['supplier_tin'] }}</td>
                  <td class="px-4 py-2.5 font-mono">{{ $r['recipient_tin'] }}</td>
                  <td class="px-4 py-2.5 tnum text-right">{{ number_format((float) $r['vat_amount'], 2, '.', ' ') }}</td>
                  <td class="px-4 py-2.5 tnum text-right font-medium">{{ number_format((float) $r['total_amount'], 2, '.', ' ') }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
      <p class="text-faint text-xs mt-2">Preview of the first {{ count($preview['sample']) }} rows.</p>

      @if($existing > 0)
        <div class="mt-4 card-flat p-3.5 text-sm flex items-start gap-2.5 {{ $fresh ? 'border-ledger/40' : 'border-amber/40' }}">
          @if($fresh)
            <span class="text-ledger">↻</span>
            <span>The existing <b>{{ number_format($existing, 0, '.', ' ') }}</b> invoices will be removed, then <b>{{ number_format($preview['count'], 0, '.', ' ') }}</b> imported. Final total: <b>{{ number_format($preview['count'], 0, '.', ' ') }}</b>.</span>
          @else
            <span class="text-amber">⚠</span>
            <span><b>{{ number_format($preview['count'], 0, '.', ' ') }}</b> rows will be <b>added</b> on top of {{ number_format($existing, 0, '.', ' ') }} → total <b>{{ number_format($existing + $preview['count'], 0, '.', ' ') }}</b> (duplicates if it's the same file). Tick “Replace existing data” to overwrite instead.</span>
          @endif
        </div>
      @endif

      <div class="flex items-center justify-between mt-5">
        <label class="flex items-center gap-2 text-sm text-muted cursor-pointer">
          <input type="checkbox" wire:model.live="fresh" class="accent-stamp"> Replace existing data (truncate first)
        </label>
        <div class="flex gap-2">
          <button wire:click="startOver" class="btn btn-ghost btn-sm">Choose another</button>
          <button wire:click="import" wire:loading.attr="disabled" wire:target="import" class="btn btn-ink btn-sm">
            <span wire:loading.remove wire:target="import">Import {{ number_format($preview['count'],0,'.',' ') }} rows →</span>
            <span wire:loading wire:target="import">Importing…</span>
          </button>
        </div>
      </div>
    @else
      <button wire:click="startOver" class="btn btn-ghost btn-sm">← Choose another file</button>
    @endif
  @endif

  {{-- STEP 3 — result --}}
  @if($step === 3)
    <div class="card p-8 text-center">
      @if($report['error'])
        <div class="text-3xl mb-3 text-stamp">✕</div>
        <h2 class="font-display text-2xl mb-2">Import failed</h2>
        <p class="text-stamp">{{ $report['error'] }}</p>
      @else
        <div class="mx-auto w-12 h-12 grid place-items-center rounded-2xl bg-ledger/12 text-ledger text-2xl mb-3">✓</div>
        <h2 class="font-display text-2xl mb-1">Imported {{ number_format($report['imported'],0,'.',' ') }} invoices</h2>
        <p class="text-muted">{{ number_format($report['total'],0,'.',' ') }} invoices in the database now.</p>
      @endif
      <div class="flex justify-center gap-2 mt-6">
        <a href="{{ route('invoices') }}" class="btn btn-ink btn-sm">Open in table</a>
        <button wire:click="startOver" class="btn btn-ghost btn-sm">Upload more</button>
      </div>
    </div>
  @endif
</section>
