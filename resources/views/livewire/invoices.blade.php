<section class="p-5 sm:p-8">
  <div class="flex items-end justify-between flex-wrap gap-3 mb-6">
    <div>
      <p class="kicker mb-1.5">{{ __(':n records', ['n' => number_format($invoices->total(), 0, '.', ' ')]) }}</p>
      <h1 class="font-display text-4xl">{{ __('Invoices') }}</h1>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
      @if($uploads->isNotEmpty())
        <select wire:model.live="upload" class="h-10 px-2.5 rounded-xl border hair bg-surface text-sm outline-none hover:border-ink transition cursor-pointer max-w-[240px]">
          <option value="">{{ __('All uploads') }}</option>
          @foreach($uploads as $u)
            <option value="{{ $u->key }}">{{ \Illuminate\Support\Str::limit($u->label, 34) }}</option>
          @endforeach
        </select>
      @endif
      <select wire:model.live="status" class="h-10 px-2.5 rounded-xl border hair bg-surface text-sm outline-none hover:border-ink transition cursor-pointer">
        <option value="">{{ __('All statuses') }}</option>
        <option value="classified">{{ __('Classified') }}</option>
        <option value="needs_review">{{ __('Needs review') }}</option>
        <option value="in_progress">{{ __('In progress') }}</option>
        <option value="trash">{{ __('Not a product') }}</option>
        <option value="rejected">{{ __('Rejected') }}</option>
      </select>
      <div class="flex items-center gap-2 bg-surface border hair rounded-xl px-3.5 h-10 w-full sm:w-80">
        <span class="text-faint">⌕</span>
        <input wire:model.live.debounce.400ms="q" placeholder="{{ __('Search item, TIN, series, number…') }}"
               class="w-full bg-transparent outline-none text-sm">
        @if($q !== '')
          <button wire:click="clear" class="text-faint hover:text-ink">✕</button>
        @endif
      </div>
      {{-- The lines the filters select, with our answer per line. A plain link: the file streams. --}}
      @if($invoices->total() > 0 && $exportParts === 1)
        <a href="{{ route('invoices.export', $exportFilters) }}" class="btn btn-ink btn-sm h-10">⤓ {{ __('Download Excel') }}</a>
      @endif
    </div>
  </div>

  @if($invoices->total() > 0 && $exportParts > 1)
    {{-- Past one file's worth of lines: one file per part, each small enough to stream in time. --}}
    <div class="card-flat px-4 py-3 mb-4 flex items-center gap-2 flex-wrap text-sm">
      <span class="text-muted">⤓ {{ __('Excel in :n parts of up to :lines lines:', ['n' => $exportParts, 'lines' => number_format($exportPartLines, 0, '.', ' ')]) }}</span>
      @for($p = 1; $p <= $exportParts; $p++)
        <a href="{{ route('invoices.export', $exportFilters + ['part' => $p]) }}" class="btn btn-ghost btn-sm"
           title="{{ number_format(($p - 1) * $exportPartLines + 1, 0, '.', ' ') }}–{{ number_format(min($p * $exportPartLines, $invoices->total()), 0, '.', ' ') }}">{{ __('Part :n', ['n' => $p]) }}</a>
      @endfor
    </div>
  @endif

  <div class="card-flat overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-muted border-b hair bg-paper/50">
            <th class="font-medium px-4 py-3">{{ __('Date') }}</th>
            <th class="font-medium px-4 py-3">{{ __('Series · No.') }}</th>
            <th class="font-medium px-4 py-3">{{ __('Item') }}</th>
            <th class="font-medium px-4 py-3">{{ __('Code') }}</th>
            <th class="font-medium px-4 py-3">{{ __('Supplier') }}</th>
            <th class="font-medium px-4 py-3">{{ __('Recipient') }}</th>
            <th class="font-medium px-4 py-3 text-right">{{ __('VAT') }}</th>
            <th class="font-medium px-4 py-3 text-right">{{ __('Total') }}</th>
          </tr>
        </thead>
        <tbody>
          @forelse($invoices as $inv)
            @php
              $ci = $inv->classificationItem;
              $aiCode = $ci?->final_code ? mb_substr((string) $ci->final_code, 0, 4) : null;
            @endphp
            <tr wire:key="inv-{{ $inv->id }}" class="border-b hair last:border-0 hover:bg-paper/40 transition">
              <td class="px-4 py-3 tnum whitespace-nowrap">{{ $inv->invoice_date?->format('d.m.Y') ?? '—' }}</td>
              <td class="px-4 py-3 font-mono whitespace-nowrap">{{ $inv->invoice_key ? $inv->series.'·'.$inv->number : '—' }}</td>
              <td class="px-4 py-3 max-w-[320px]">
                @if($inv->item_name)
                  <div class="truncate" title="{{ $inv->item_name }}">{{ $inv->item_name }}</div>
                  @if($inv->unit || $inv->quantity !== null)
                    <div class="text-faint text-xs tnum">{{ $inv->quantity !== null ? rtrim(rtrim(number_format((float) $inv->quantity, 4, '.', ' '), '0'), '.') : '' }} {{ $inv->unit }}</div>
                  @endif
                @else
                  <span class="text-faint">—</span>
                @endif
              </td>
              <td class="px-4 py-3 whitespace-nowrap">
                @if($aiCode)
                  <a href="{{ route('review.decision', ['item' => $ci->id]) }}" target="_blank"
                     class="font-mono link-under {{ ($headingNames[$aiCode] ?? null) ? 'hint-head' : '' }}"
                     @if($headingNames[$aiCode] ?? null) data-hint-title="{{ $aiCode }}" data-hint-body="{{ $headingNames[$aiCode] }}" @endif>{{ $aiCode }}</a>
                @elseif($ci)
                  <span class="text-faint text-xs">{{ match (true) {
                      $ci->resolution === 'pending' || $ci->isResolving() => __('classifying…'),
                      $ci->resolution === 'rejected' => __('rejected'),
                      $ci->resolution === 'trash' => __('trash'),
                      default => __('needs review'),
                  } }}</span>
                @else
                  <span class="text-faint">—</span>
                @endif
                @if($inv->declared_code)
                  <div class="text-faint text-xs font-mono" title="{{ $inv->declared_group }}">{{ __('decl.') }} {{ $inv->declared_code }}</div>
                @endif
              </td>
              <td class="px-4 py-3 font-mono">
                @if($inv->supplier_tin)
                  {{-- Opens the AI chat on this taxpayer ("this taxpayer" mode). --}}
                  <a href="{{ route('ask', ['tin' => $inv->supplier_tin]) }}" class="hover:text-stamp" title="{{ __('Ask the AI chat about this taxpayer') }}">{{ $inv->supplier_tin }}</a>
                @else
                  —
                @endif
              </td>
              <td class="px-4 py-3 font-mono">
                @if($inv->recipient_tin)
                  {{-- Opens the AI chat on this taxpayer ("this taxpayer" mode). --}}
                  <a href="{{ route('ask', ['tin' => $inv->recipient_tin]) }}" class="hover:text-stamp" title="{{ __('Ask the AI chat about this taxpayer') }}">{{ $inv->recipient_tin }}</a>
                @else
                  —
                @endif
              </td>
              <td class="px-4 py-3 tnum text-right whitespace-nowrap">{{ number_format($inv->vat_amount, 2, '.', ' ') }}</td>
              <td class="px-4 py-3 tnum text-right whitespace-nowrap font-medium">{{ number_format($inv->total_amount, 2, '.', ' ') }}</td>
            </tr>
          @empty
            <tr><td colspan="8" class="px-4 py-10 text-center text-muted">{{ __('No invoices match “:q”.', ['q' => $q]) }}</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-5">
    {{ $invoices->onEachSide(1)->links() }}
  </div>
</section>
