<section class="p-5 sm:p-8 max-w-[1180px]">
  <div class="mb-5">
    <p class="kicker mb-1.5">Audit</p>
    <h1 class="font-display text-4xl">Activity log</h1>
    <p class="text-muted text-sm mt-1">Everything that happened — actions and LLM decisions — correlated by request id.</p>
  </div>

  {{-- Tabs --}}
  <div class="flex flex-wrap gap-2 mb-4">
    @foreach(['activity' => 'Actions', 'llm' => 'LLM decisions'] as $key => $label)
      <button wire:click="setTab('{{ $key }}')"
              class="px-3 py-1.5 rounded-lg text-sm border hair transition {{ $tab === $key ? 'bg-ink text-paper border-ink' : 'bg-surface hover:border-ink' }}">
        {{ $label }}
      </button>
    @endforeach
  </div>

  {{-- Filters --}}
  <div class="flex flex-wrap items-center gap-2 mb-4">
    <select wire:model.live="action" class="px-3 py-1.5 rounded-lg text-sm border hair bg-surface focus:border-ink outline-none">
      <option value="">{{ $tab === 'llm' ? 'All purposes' : 'All actions' }}</option>
      @foreach($actions as $a)
        <option value="{{ $a }}">{{ $a }}</option>
      @endforeach
    </select>
    <input wire:model.live.debounce.400ms="requestId" placeholder="request id"
           class="px-3 py-1.5 rounded-lg text-sm border hair bg-surface focus:border-ink outline-none font-mono w-[290px] max-w-full">
    <input wire:model.live.debounce.400ms="q" placeholder="search…"
           class="px-3 py-1.5 rounded-lg text-sm border hair bg-surface focus:border-ink outline-none flex-1 min-w-[140px]">
    @if($requestId !== '' || $q !== '' || $action !== '')
      <button wire:click="$set('requestId',''); $set('q',''); $set('action','')" class="btn btn-ghost btn-sm">Clear</button>
    @endif
  </div>

  <div class="card-flat overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-muted border-b hair bg-paper/50">
            <th class="font-medium px-4 py-2.5 whitespace-nowrap">Time</th>
            @if($tab === 'llm')
              <th class="font-medium px-4 py-2.5">Purpose</th>
              <th class="font-medium px-4 py-2.5">Model</th>
              <th class="font-medium px-4 py-2.5">Status</th>
              <th class="font-medium px-4 py-2.5 text-right">Tokens</th>
              <th class="font-medium px-4 py-2.5 text-right">Latency</th>
            @else
              <th class="font-medium px-4 py-2.5">User</th>
              <th class="font-medium px-4 py-2.5">Action</th>
              <th class="font-medium px-4 py-2.5">Subject</th>
            @endif
            <th class="font-medium px-4 py-2.5">Request</th>
            <th class="font-medium px-4 py-2.5"></th>
          </tr>
        </thead>
        <tbody>
          @forelse($rows as $row)
            <tr wire:key="row-{{ $tab }}-{{ $row->id }}" x-data="{open:false}" @click="open=!open" class="border-b hair last:border-0 align-top cursor-pointer hover:bg-paper/40">
              <td class="px-4 py-2.5 tnum whitespace-nowrap text-muted">{{ \Illuminate\Support\Carbon::parse($row->created_at)->format('Y-m-d H:i:s') }}</td>

              @if($tab === 'llm')
                <td class="px-4 py-2.5 whitespace-nowrap">
                  {{ $row->purpose }}@if($row->tier)<span class="text-faint"> · {{ $row->tier }}</span>@endif
                </td>
                <td class="px-4 py-2.5 font-mono text-xs">{{ $row->model }}</td>
                <td class="px-4 py-2.5">
                  <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $row->status === 'error' ? 'bg-stamp/12 text-stamp' : 'bg-ledger/12 text-ledger' }}">{{ $row->status ?? '—' }}</span>
                </td>
                <td class="px-4 py-2.5 tnum text-right">{{ number_format((int) $row->total_tokens) }}</td>
                <td class="px-4 py-2.5 tnum text-right text-muted">{{ $row->latency_ms !== null ? $row->latency_ms.' ms' : '—' }}</td>
              @else
                <td class="px-4 py-2.5 whitespace-nowrap">{{ $row->user?->name ?? ($row->user_id ? '#'.$row->user_id : 'system') }}</td>
                <td class="px-4 py-2.5"><span class="px-2 py-0.5 rounded-md text-xs font-medium bg-line/40 text-ink whitespace-nowrap">{{ $row->action }}</span></td>
                <td class="px-4 py-2.5 text-muted text-xs whitespace-nowrap">{{ $row->subject_type ? class_basename($row->subject_type).' #'.$row->subject_id : '—' }}</td>
              @endif

              <td class="px-4 py-2.5">
                @if($row->request_id)
                  <button @click.stop="$wire.set('requestId', '{{ $row->request_id }}')"
                          class="font-mono text-xs text-muted hover:text-ink" title="Filter by this request">{{ Str::limit($row->request_id, 8, '') }}…</button>
                @else
                  <span class="text-faint">—</span>
                @endif
              </td>
              <td class="px-4 py-2.5 text-faint text-xs" x-text="open ? '▾' : '▸'"></td>
            </tr>
            <tr x-show="open" wire:key="det-{{ $tab }}-{{ $row->id }}" class="border-b hair last:border-0 bg-paper/30">
              <td colspan="{{ $tab === 'llm' ? 7 : 6 }}" class="px-4 py-3">
                @if($tab === 'llm')
                  @if($row->error)<p class="text-stamp text-sm mb-2"><b>Error:</b> {{ $row->error }}</p>@endif
                  <p class="kicker mb-1">Prompt</p>
                  @php $pp = $row->prompt ? json_decode($row->prompt, true) : null; @endphp
                  <pre class="text-xs font-mono bg-inkpanel text-paper rounded-lg p-3 overflow-x-auto whitespace-pre-wrap mb-3">{{ $row->prompt ? json_encode($pp ?? $row->prompt, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : '(payload logging off)' }}</pre>
                  <p class="kicker mb-1">Response</p>
                  <pre class="text-xs font-mono bg-surface border hair rounded-lg p-3 overflow-x-auto whitespace-pre-wrap">{{ $row->response ?? '(payload logging off)' }}</pre>
                  <p class="text-faint text-xs mt-2 font-mono">request: {{ $row->request_id ?? '—' }}</p>
                @else
                  <pre class="text-xs font-mono bg-surface border hair rounded-lg p-3 overflow-x-auto whitespace-pre-wrap">{{ $row->properties ? json_encode($row->properties, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : '(no properties)' }}</pre>
                  <p class="text-faint text-xs mt-2">IP: {{ $row->ip ?? '—' }} · request: <span class="font-mono">{{ $row->request_id ?? '—' }}</span></p>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="{{ $tab === 'llm' ? 7 : 6 }}" class="px-4 py-10 text-center text-muted">Nothing logged yet for this filter.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-4">{{ $rows->onEachSide(1)->links() }}</div>
</section>
