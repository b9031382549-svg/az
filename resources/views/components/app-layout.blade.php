<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? config('app.name') }}</title>
@include('partials.theme')
@livewireStyles
</head>
<body class="font-sans">
@php
    $nav = fn (string $route) => request()->routeIs($route) ? 'nav-item active' : 'nav-item';
    $initial = mb_strtoupper(mb_substr(auth()->user()->name ?? 'A', 0, 1));
@endphp

<div class="min-h-screen flex">

  <!-- SIDEBAR -->
  <aside class="hidden md:flex flex-col w-60 shrink-0 bg-paper border-r hair">
    <div class="h-16 flex items-center gap-2.5 px-5 border-b hair">
      <div class="w-7 h-7 grid place-items-center bg-ink text-paper rounded-md font-display font-semibold leading-none">I</div>
      <span class="font-display text-lg tracking-tight">Invoice<span class="text-stamp">·</span>Intel</span>
    </div>
    <nav class="flex-1 py-4">
      <a href="{{ route('overview') }}" class="{{ $nav('overview') }}">
        <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 20 20"><rect x="2.5" y="2.5" width="6" height="6" rx="1.2"/><rect x="11.5" y="2.5" width="6" height="6" rx="1.2"/><rect x="2.5" y="11.5" width="6" height="6" rx="1.2"/><rect x="11.5" y="11.5" width="6" height="6" rx="1.2"/></svg>Overview</a>
      <a href="{{ route('invoices') }}" class="{{ $nav('invoices') }}">
        <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 20 20"><path d="M5 2.5h7l3 3v12H5z" stroke-linejoin="round"/><path d="M12 2.5v3.5h3M7.5 9.5h5M7.5 12.5h5"/></svg>Invoices</a>
      <a href="{{ route('upload') }}" class="{{ $nav('upload') }}">
        <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 20 20" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13V4M6.5 7.5L10 4l3.5 3.5M3.5 13.5v2a1 1 0 001 1h11a1 1 0 001-1v-2"/></svg>Upload</a>
      <a href="{{ route('ask') }}" class="{{ $nav('ask') }}">
        <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 20 20" stroke-linejoin="round"><path d="M3 4.5h14v9H8l-4 3v-3H3z"/></svg>AI Chat</a>

      <div class="px-5 pt-4 pb-1.5"><p class="kicker">Classifier</p></div>
      <a href="{{ route('classify') }}" class="{{ $nav('classify') }}">
        <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 20 20" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10l4 4 10-10"/><path d="M3 16h7"/></svg>Classify</a>
      <a href="{{ route('review') }}" class="{{ $nav('review') }}">
        <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 20 20" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="10" r="7.5"/><path d="M10 6v4l2.5 2"/></svg>Review queue</a>
      <a href="{{ route('catalog') }}" class="{{ $nav('catalog') }}">
        <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 20 20" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="9" r="5.5"/><path d="M13.5 13.5L17 17"/></svg>Catalog</a>

      <div class="mx-5 my-3 border-t hair"></div>
      <a href="{{ route('settings') }}" class="{{ $nav('settings') }}">
        <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2.6"/><path d="M10 1.5v2M10 16.5v2M3.5 3.5l1.4 1.4M15.1 15.1l1.4 1.4M1.5 10h2M16.5 10h2M3.5 16.5l1.4-1.4M15.1 4.9l1.4-1.4"/></svg>Settings</a>
    </nav>
    <div class="p-4 border-t hair">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-full bg-ledger/15 text-ledger grid place-items-center font-semibold">{{ $initial }}</div>
        <div class="text-sm leading-tight"><div class="font-medium">{{ auth()->user()->name }}</div><div class="text-faint text-xs">{{ config('app.organization') }}</div></div>
      </div>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="flex-1 flex flex-col min-w-0">

    <!-- TOPBAR -->
    <header class="h-16 shrink-0 flex items-center gap-4 px-5 sm:px-7 border-b hair bg-paper/80 backdrop-blur sticky top-0 z-30">
      <button class="md:hidden text-xl" onclick="document.querySelector('aside').classList.toggle('hidden')">≡</button>
      <form action="{{ route('invoices') }}" method="GET" class="flex-1 max-w-md flex items-center gap-2 bg-surface border hair rounded-xl px-3.5 h-10">
        <span class="text-faint">⌕</span>
        <input name="q" value="{{ request('q') }}" placeholder="Search invoices, TIN, number…" class="w-full bg-transparent outline-none text-sm">
      </form>
      <div class="ml-auto flex items-center gap-3">
        <button class="w-10 h-10 grid place-items-center border hair rounded-xl bg-surface hover:border-ink transition" title="Notifications">◔</button>
        <form method="POST" action="{{ route('logout') }}">
          @csrf
          <button type="submit" class="flex items-center gap-2 border hair rounded-xl bg-surface px-2.5 h-10 hover:border-ink transition">
            <span class="w-6 h-6 rounded-full bg-ledger/15 text-ledger grid place-items-center text-xs font-semibold">{{ $initial }}</span>
            <span class="text-sm hidden sm:inline">Sign out</span>
          </button>
        </form>
      </div>
    </header>

    <main class="flex-1 overflow-y-auto">
      {{ $slot }}
    </main>
  </div>
</div>
@livewireScripts
</body>
</html>
