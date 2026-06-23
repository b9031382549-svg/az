<x-app-layout :title="'Upload · '.config('app.name')">
<section class="p-5 sm:p-8 max-w-[920px]">
  <div class="mb-7">
    <p class="kicker mb-1.5">Data</p>
    <h1 class="font-display text-4xl">Upload invoices</h1>
  </div>

  <div class="card p-8 text-center">
    <div class="mx-auto w-14 h-14 grid place-items-center rounded-2xl bg-paper border hair text-2xl mb-4">⬆</div>
    <h2 class="font-display text-xl mb-2">Drop your e-invoice export here</h2>
    <p class="text-muted mb-6">Excel (.xlsx) exports with the standard 15-column layout are parsed automatically.</p>
    <button class="btn btn-ink btn-sm" disabled title="Wired in a later iteration">Choose file</button>
  </div>

  <div class="card-flat p-5 mt-5 text-sm text-muted">
    The sample dataset <span class="font-mono text-ink">FoodWholesale_sampleData.xlsx</span> is already loaded.
    To re-import from the CLI: <span class="font-mono text-ink">php artisan data:import-invoices --fresh</span>
  </div>
</section>
</x-app-layout>
