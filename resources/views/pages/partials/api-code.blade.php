{{-- A code sample with a copy button. Copies through execCommand: the clipboard API needs HTTPS.
     Pass label explicitly — @include also sees the parent's variables. The label is not
     uppercased: it often holds a path, and paths are case-sensitive. --}}
<div x-data="{ copied: false }">
  @if(! empty($label))<p class="text-xs font-medium text-muted mb-1.5">{{ $label }}</p>@endif
  <div class="relative">
    <pre x-ref="code" class="text-xs font-mono bg-inkpanel text-paper rounded-xl p-4 pr-24 overflow-x-auto whitespace-pre-wrap break-words leading-relaxed">{{ $code }}</pre>
    <button type="button" class="absolute top-2.5 right-2.5 text-[11px] px-2 py-1 rounded-md bg-paper/10 text-paper/80 hover:bg-paper/20 transition"
            @click="apiDocsCopy($refs.code.innerText); copied = true; setTimeout(() => copied = false, 1500)">
      <span x-show="! copied">Копировать</span><span x-show="copied" x-cloak>Скопировано</span>
    </button>
  </div>
</div>
