<section class="p-5 sm:p-8 max-w-[1080px]">
  @php
    $nf = fn ($n) => number_format((float) $n, 0, '.', ' ');
    $formatLabel = fn ($f) => match ($f) {
        'sablon' => __('Line-level export (Şablon)'),
        'legacy' => __('Invoice list (15 columns)'),
        default => '—',
    };
  @endphp

  <div class="flex items-end justify-between flex-wrap gap-3 mb-6">
    <div>
      <p class="kicker mb-1.5">{{ __('Data import') }}</p>
      <h1 class="font-display text-4xl">{{ __('Upload invoices') }}</h1>
    </div>
    <div class="flex items-center gap-3">
      <div class="card-flat px-4 py-2.5 text-sm">
        <span class="text-muted">{{ __('In the database now:') }}</span>
        <span class="font-display text-lg tnum ml-1">{{ $nf($existing) }}</span>
        <span class="text-muted">{{ __('rows') }}</span>
      </div>
      @if($existing > 0)
        <button wire:click="deleteAll"
                wire:confirm="{{ __('Delete all :n rows? This cannot be undone.', ['n' => $nf($existing)]) }}"
                class="btn btn-ghost btn-sm text-stamp">
          {{ __('Delete all invoices') }}
        </button>
      @endif
    </div>
  </div>

  @php
    $steps = [1 => __('File'), 2 => __('Preview'), 3 => __('Result')];
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
    <label class="dropzone card bg-surface border-2 border-dashed hair p-12 text-center block cursor-pointer hover:border-ink transition"
           x-data
           @dragover.prevent="$el.classList.add('drag')"
           @dragenter.prevent="$el.classList.add('drag')"
           @dragleave.prevent="$el.classList.remove('drag')"
           @drop.prevent="$el.classList.remove('drag');
                          $refs.fileInput.files = $event.dataTransfer.files;
                          $refs.fileInput.dispatchEvent(new Event('change', { bubbles: true }))">
      <input x-ref="fileInput" type="file" wire:model="file" class="hidden" accept=".xlsx,.xls,.csv">
      <div wire:loading.remove wire:target="file">
        <div class="text-4xl mb-3 text-faint">⬆</div>
        <p class="font-display text-2xl mb-1">{{ __('Drag your file here') }}</p>
        <p class="text-muted mb-5">{{ __('or click to choose a file') }}</p>
        <span class="btn btn-ink">{{ __('Choose file') }}</span>
        <p class="text-xs text-faint mt-5">{{ __('Supported: .xlsx, .xls, .csv · up to 25') }}&nbsp;{{ __('MB') }}</p>
      </div>
      <div wire:loading wire:target="file" class="py-6">
        <p class="font-display text-xl">{{ __('Reading file…') }}</p>
      </div>
    </label>
    @error('file') <p class="text-sm text-stamp mt-3">{{ $message }}</p> @enderror
    <div class="mt-4 flex items-start gap-2.5 text-sm text-muted card-flat p-3.5">
      <span class="text-amber">ℹ</span>
      <span>{!! __('Two layouts are recognised automatically: the <b>line-level export (Şablon)</b> — one row per invoice line with the item name; its items are <b>classified right away</b> (up to :max lines per file); and the <b>invoice list</b> with the 15 standard columns (No., supplier/recipient TIN, dates, series, number, the VAT amount columns and total).', ['max' => $nf($maxLines)]) !!}</span>
    </div>
    @if($existing > 0)
      <div class="mt-3 flex items-start gap-2.5 text-sm card-flat p-3.5 border-amber/40">
        <span class="text-amber">⚠</span>
        <span>{!! __('The database already holds <b>:n</b> rows. A new import is <b>added on top</b> by default — tick <b>“Skip duplicates”</b> on the next step to avoid re-adding invoices already in the database (matched by series + number).', ['n' => $nf($existing)]) !!}</span>
      </div>
    @endif
  @endif

  {{-- STEP 2 — preview --}}
  @if($step === 2)
    <div class="card-flat p-5 mb-4">
      <div class="flex items-center justify-between flex-wrap gap-2">
        <div class="flex items-center gap-2.5 flex-wrap">
          <span class="font-mono text-sm">{{ $file?->getClientOriginalName() }}</span>
          @if($preview['format'] ?? null)
            <span class="px-2 py-0.5 rounded-md text-xs bg-line/40 text-muted">{{ $formatLabel($preview['format']) }}</span>
          @endif
        </div>
        @if($preview['ok'])
          <span class="text-ledger text-sm">{{ $nf($preview['count']) }} {{ __('rows found ✓') }}</span>
        @else
          <span class="text-stamp text-sm">{{ $preview['error'] }}</span>
        @endif
      </div>
    </div>

    @if($preview['ok'] && ($preview['format'] ?? null) === 'sablon')
      {{-- Line-level export: how much of it can be tied to specific invoices. --}}
      @php $s = $preview['stats']; @endphp
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div class="card-flat p-3.5">
          <p class="kicker mb-1">{{ __('Invoices identified') }}</p>
          <p class="font-display text-2xl tnum">{{ $nf($s['invoices']) }}</p>
          <p class="text-faint text-xs">{{ __(':n lines with series + number', ['n' => $nf($s['identified_lines'])]) }}</p>
        </div>
        <div class="card-flat p-3.5 {{ $s['unidentified_lines'] > 0 ? 'border-amber/40' : '' }}">
          <p class="kicker mb-1">{{ __('Without invoice number') }}</p>
          <p class="font-display text-2xl tnum {{ $s['unidentified_lines'] > 0 ? 'text-amber' : '' }}">{{ $nf($s['unidentified_lines']) }}</p>
          <p class="text-faint text-xs">{{ __('lines not tied to a specific invoice') }}</p>
        </div>
        <div class="card-flat p-3.5">
          <p class="kicker mb-1">{{ __('Without VÖEN') }}</p>
          <p class="font-display text-2xl tnum">{{ $nf($s['no_supplier_tin']) }} <span class="text-faint text-base">/</span> {{ $nf($s['no_recipient_tin']) }}</p>
          <p class="text-faint text-xs">{{ __('lines: supplier / recipient') }}</p>
        </div>
        <div class="card-flat p-3.5">
          <p class="kicker mb-1">{{ __('To classify') }}</p>
          <p class="font-display text-2xl tnum">{{ $nf($s['unique_items']) }}</p>
          <p class="text-faint text-xs">{{ __('unique item names') }}@if($s['no_item'] > 0) · {{ __(':n lines without a name', ['n' => $nf($s['no_item'])]) }}@endif</p>
        </div>
      </div>
      @if($s['no_date'] > 0)
        <p class="text-amber text-sm mb-3">⚠ {{ __(':n lines have no invoice date.', ['n' => $nf($s['no_date'])]) }}</p>
      @endif
      @if($preview['same_file'])
        <div class="mb-4 card-flat p-3.5 text-sm flex items-start gap-2.5 border-amber/40">
          <span class="text-amber">⚠</span>
          <span>{!! __('This exact file was already uploaded as <b>:label</b> (:at). Importing it again adds its lines a second time — lines without an invoice number cannot be recognised as duplicates.', ['label' => e($preview['same_file']['label']), 'at' => $preview['same_file']['at']]) !!}</span>
        </div>
      @endif

      <div class="card-flat overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-muted border-b hair bg-paper/50">
                <th class="font-medium px-4 py-2.5">{{ __('Date') }}</th>
                <th class="font-medium px-4 py-2.5">{{ __('Series·No.') }}</th>
                <th class="font-medium px-4 py-2.5">{{ __('Item') }}</th>
                <th class="font-medium px-4 py-2.5">{{ __('Declared code') }}</th>
                <th class="font-medium px-4 py-2.5">{{ __('Unit') }}</th>
                <th class="font-medium px-4 py-2.5 text-right">{{ __('Qty') }}</th>
                <th class="font-medium px-4 py-2.5 text-right">{{ __('Total') }}</th>
              </tr>
            </thead>
            <tbody>
              @foreach($preview['sample'] as $r)
                <tr class="border-b hair last:border-0">
                  <td class="px-4 py-2.5 tnum whitespace-nowrap">{{ $r['invoice_date'] ?? '—' }}</td>
                  <td class="px-4 py-2.5 font-mono whitespace-nowrap">{{ $r['invoice_key'] ? $r['series'].'·'.$r['number'] : '—' }}</td>
                  <td class="px-4 py-2.5 max-w-[280px] truncate" title="{{ $r['item_name'] }}">{{ $r['item_name'] ?? '—' }}</td>
                  <td class="px-4 py-2.5 font-mono whitespace-nowrap" title="{{ $r['declared_group'] }}">{{ $r['declared_code'] ?? '—' }}</td>
                  <td class="px-4 py-2.5 whitespace-nowrap">{{ $r['unit'] ?? '—' }}</td>
                  <td class="px-4 py-2.5 tnum text-right">{{ $r['quantity'] !== null ? rtrim(rtrim(number_format((float) $r['quantity'], 4, '.', ' '), '0'), '.') : '—' }}</td>
                  <td class="px-4 py-2.5 tnum text-right font-medium">{{ number_format((float) $r['total_amount'], 2, '.', ' ') }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
      <p class="text-faint text-xs mt-2">{{ __('Preview of the first :n rows.', ['n' => count($preview['sample'])]) }}</p>

      @if(($preview['duplicates'] ?? 0) > 0)
        <div class="mt-4 card-flat p-3.5 text-sm flex items-start gap-2.5 {{ $skipDuplicates ? 'border-ledger/40' : 'border-amber/40' }}">
          <span class="{{ $skipDuplicates ? 'text-ledger' : 'text-amber' }}">{{ $skipDuplicates ? '↻' : '⚠' }}</span>
          <span>{!! __('<b>:n</b> lines belong to <b>:inv</b> invoices already in the database (matched by series + number).', ['n' => $nf($preview['duplicates']), 'inv' => $nf($preview['duplicate_invoices'])]) !!}
            {{ $skipDuplicates ? __('They will be skipped.') : __('Tick “Skip duplicates” to avoid re-adding them.') }}</span>
        </div>
      @endif

      <div class="flex items-center justify-between mt-5 flex-wrap gap-3">
        <label class="flex items-center gap-2 text-sm text-muted cursor-pointer">
          <input type="checkbox" wire:model.live="skipDuplicates" class="accent-stamp"> {{ __('Skip duplicate invoices (matched by series + number)') }}
        </label>
        <div class="flex gap-2">
          <button wire:click="startOver" class="btn btn-ghost btn-sm">{{ __('Choose another') }}</button>
          <button wire:click="import" wire:loading.attr="disabled" wire:target="import" class="btn btn-ink btn-sm">
            <span wire:loading.remove wire:target="import">{{ __('Import :n lines and classify →', ['n' => $nf($preview['count'])]) }}</span>
            <span wire:loading wire:target="import">{{ __('Importing…') }}</span>
          </button>
        </div>
      </div>
    @elseif($preview['ok'])
      <div class="card-flat overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-muted border-b hair bg-paper/50">
                <th class="font-medium px-4 py-2.5">{{ __('Date') }}</th>
                <th class="font-medium px-4 py-2.5">{{ __('Series·No.') }}</th>
                <th class="font-medium px-4 py-2.5">{{ __('Supplier') }}</th>
                <th class="font-medium px-4 py-2.5">{{ __('Recipient') }}</th>
                <th class="font-medium px-4 py-2.5 text-right">{{ __('VAT') }}</th>
                <th class="font-medium px-4 py-2.5 text-right">{{ __('Total') }}</th>
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
      <p class="text-faint text-xs mt-2">{{ __('Preview of the first :n rows.', ['n' => count($preview['sample'])]) }}</p>

      @if($existing > 0)
        <div class="mt-4 card-flat p-3.5 text-sm flex items-start gap-2.5 {{ $skipDuplicates ? 'border-ledger/40' : 'border-amber/40' }}">
          @if($skipDuplicates)
            <span class="text-ledger">↻</span>
            <span>{!! __('<b>:new</b> new invoices will be added. <b>:dupes</b> already in the database (matched by series + number) will be skipped. Final total: <b>:total</b>.', [
              'new' => $nf($preview['count'] - ($preview['duplicates'] ?? 0)),
              'dupes' => $nf($preview['duplicates'] ?? 0),
              'total' => $nf($existing + $preview['count'] - ($preview['duplicates'] ?? 0)),
            ]) !!}</span>
          @else
            <span class="text-amber">⚠</span>
            <span>{!! __('<b>:n</b> rows will be <b>added</b> on top of :existing → total <b>:total</b>.', [
              'n' => $nf($preview['count']),
              'existing' => $nf($existing),
              'total' => $nf($existing + $preview['count']),
            ]) !!}
            @if(($preview['duplicates'] ?? 0) > 0)
              {!! __('<b>:n</b> of these look like duplicates already in the database.', ['n' => $nf($preview['duplicates'])]) !!}
            @endif
            {{ __('Tick “Skip duplicates” to avoid re-adding them.') }}</span>
          @endif
        </div>
      @endif

      <div class="flex items-center justify-between mt-5">
        <label class="flex items-center gap-2 text-sm text-muted cursor-pointer">
          <input type="checkbox" wire:model.live="skipDuplicates" class="accent-stamp"> {{ __('Skip duplicate invoices (matched by series + number)') }}
        </label>
        <div class="flex gap-2">
          <button wire:click="startOver" class="btn btn-ghost btn-sm">{{ __('Choose another') }}</button>
          <button wire:click="import" wire:loading.attr="disabled" wire:target="import" class="btn btn-ink btn-sm">
            <span wire:loading.remove wire:target="import">{{ __('Import') }} {{ $nf($preview['count']) }} {{ __('rows →') }}</span>
            <span wire:loading wire:target="import">{{ __('Importing…') }}</span>
          </button>
        </div>
      </div>
    @else
      <button wire:click="startOver" class="btn btn-ghost btn-sm">{{ __('← Choose another file') }}</button>
    @endif
  @endif

  {{-- STEP 3 — result --}}
  @if($step === 3)
    <div class="card p-8 text-center">
      @if($report['error'])
        <div class="text-3xl mb-3 text-stamp">✕</div>
        <h2 class="font-display text-2xl mb-2">{{ __('Import failed') }}</h2>
        <p class="text-stamp">{{ $report['error'] }}</p>
      @elseif(($report['format'] ?? null) === 'sablon')
        @php $s = $report['stats']; @endphp
        <div class="mx-auto w-12 h-12 grid place-items-center rounded-2xl bg-ledger/12 text-ledger text-2xl mb-3">✓</div>
        <h2 class="font-display text-2xl mb-1">{{ __('Imported :n invoice lines', ['n' => $nf($report['imported'])]) }}</h2>
        <p class="text-muted text-sm">
          {{ __(':inv invoices identified · :un lines without invoice number', ['inv' => $nf($s['invoices'] ?? 0), 'un' => $nf($s['unidentified_lines'] ?? 0)]) }}
        </p>
        @if(($report['skipped'] ?? 0) > 0)
          <p class="text-muted text-sm">{{ __(':n lines of invoices already in the database skipped', ['n' => $nf($report['skipped'])]) }}</p>
        @endif
        <p class="text-muted">{{ __(':n unique item names sent to classification.', ['n' => $nf($report['items'] ?? 0)]) }}</p>
      @else
        <div class="mx-auto w-12 h-12 grid place-items-center rounded-2xl bg-ledger/12 text-ledger text-2xl mb-3">✓</div>
        <h2 class="font-display text-2xl mb-1">{{ __('Imported') }} {{ $nf($report['imported']) }} {{ __('invoices') }}</h2>
        @if(($report['skipped'] ?? 0) > 0)
          <p class="text-muted text-sm">{{ $nf($report['skipped']) }} {{ __('duplicates skipped') }}</p>
        @endif
        <p class="text-muted">{{ $nf($report['total']) }} {{ __('rows in the database now.') }}</p>
      @endif
      <div class="flex justify-center gap-2 mt-6">
        <a href="{{ route('invoices') }}" class="btn btn-ink btn-sm">{{ __('Open in table') }}</a>
        <button wire:click="startOver" class="btn btn-ghost btn-sm">{{ __('Upload more') }}</button>
      </div>
    </div>

    {{-- The classification this upload started — the same live panel as on Classify. --}}
    @if($progress)
      {{-- No second "Upload more" — the result card above already has one. --}}
      @include('livewire.partials.batch-progress', ['moreLabel' => null, 'from' => 'upload'])
    @endif
  @endif

  {{-- Uploads still in the table — each can be deleted on its own. --}}
  @if($step !== 2 && $uploads->isNotEmpty())
    <div class="card-flat overflow-hidden mt-8">
      <div class="px-4 py-3 border-b hair flex items-center justify-between">
        <p class="kicker">{{ __('Recent uploads') }}</p>
        <span class="text-faint text-xs">{{ __('Deleting an upload removes its invoice rows; its classification stays in Review and memory.') }}</span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-muted border-b hair bg-paper/50">
              <th class="font-medium px-4 py-2.5">{{ __('File') }}</th>
              <th class="font-medium px-4 py-2.5">{{ __('Layout') }}</th>
              <th class="font-medium px-4 py-2.5">{{ __('Uploaded') }}</th>
              <th class="font-medium px-4 py-2.5 text-right">{{ __('Rows') }}</th>
              <th class="font-medium px-4 py-2.5 text-right">{{ __('Items') }}</th>
              <th class="px-4 py-2.5"></th>
            </tr>
          </thead>
          <tbody>
            @foreach($uploads as $u)
              <tr class="border-b hair last:border-0" wire:key="upload-{{ $u->key }}">
                <td class="px-4 py-2.5 max-w-[280px] truncate" title="{{ $u->label }}">{{ $u->label }}</td>
                <td class="px-4 py-2.5 text-muted whitespace-nowrap">{{ $formatLabel($u->format) }}</td>
                <td class="px-4 py-2.5 text-muted whitespace-nowrap tnum">{{ $u->at?->format('Y-m-d H:i') }}</td>
                <td class="px-4 py-2.5 tnum text-right">{{ $nf($u->lines) }}</td>
                <td class="px-4 py-2.5 tnum text-right">
                  @if($u->items > 0)
                    <a href="{{ route('review.batch', ['batch' => $u->key, 'filter' => 'all']) }}" class="link-under">{{ $nf($u->items) }}</a>
                  @else
                    <span class="text-faint">—</span>
                  @endif
                </td>
                <td class="px-4 py-2.5 text-right">
                  <button wire:click="deleteUpload('{{ $u->key }}')"
                          wire:confirm="{{ __('Delete the :n rows of “:file”? Its classification stays in Review.', ['n' => $nf($u->lines), 'file' => $u->label]) }}"
                          class="btn btn-ghost btn-sm text-stamp">{{ __('Delete') }}</button>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endif
</section>
