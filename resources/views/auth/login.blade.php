<x-guest-layout :title="'Sign in · '.config('app.name')">
<div class="min-h-screen grid md:grid-cols-2">

  <!-- form column -->
  <div class="flex flex-col px-7 sm:px-14 py-10 bg-paper">
    <div class="flex items-center gap-2.5">
      <div class="w-8 h-8 grid place-items-center bg-ink text-paper rounded-lg font-display font-semibold text-lg leading-none">I</div>
      <span class="font-display text-xl tracking-tight">Invoice<span class="text-stamp">·</span>Intelligence</span>
    </div>

    <div class="flex-1 grid place-items-center py-10">
      <div class="w-full max-w-[380px]">
        <p class="kicker mb-3">Account</p>
        <h1 class="font-display text-[2.5rem] leading-[1.05] mb-2">Welcome back.</h1>
        <p class="text-muted mb-9">Sign in to continue working with your invoices.</p>

        <form method="POST" action="{{ route('login.attempt') }}">
          @csrf
          <div class="space-y-5">
            <div>
              <label class="field-label">Login</label>
              <input name="login" type="text" value="{{ old('login', 'admin') }}" autofocus
                     class="field-input @error('login') ring-1 ring-stamp @enderror">
            </div>
            <div>
              <label class="field-label">Password</label>
              <div class="relative">
                <input name="password" type="password" value="admin" class="field-input pr-12">
                <button type="button" class="absolute right-0 top-0 h-full px-3.5 text-faint hover:text-ink"
                        onclick="const i=this.previousElementSibling; i.type=i.type==='password'?'text':'password'">◍</button>
              </div>
            </div>
          </div>

          @error('login')
            <p class="text-sm text-stamp mt-3">{{ $message }}</p>
          @enderror

          <div class="flex items-center justify-between text-sm mt-4 mb-7">
            <label class="flex items-center gap-2 text-muted cursor-pointer">
              <input name="remember" type="checkbox" class="accent-stamp"> Remember me
            </label>
          </div>

          <button type="submit" class="btn btn-ink w-full">Sign in</button>
        </form>

        <p class="text-sm text-muted mt-6">Demo credentials: <span class="font-mono text-ink">admin / admin</span></p>
      </div>
    </div>
    <p class="text-xs text-faint">© 2026 Invoice Intelligence · interface prototype</p>
  </div>

  <!-- editorial panel -->
  <div class="hidden md:flex relative bg-inkpanel text-paper overflow-hidden">
    <div class="absolute inset-0 opacity-[0.07]"
         style="background-image:linear-gradient(#F2EEE3 1px,transparent 1px),linear-gradient(90deg,#F2EEE3 1px,transparent 1px);background-size:34px 34px"></div>
    <div class="relative z-10 flex flex-col justify-between p-14 w-full">
      <p class="kicker text-faint">e-Invoices · VAT · analytics</p>
      <div>
        <p class="font-display text-[2.9rem] leading-[1.08]">“How much VAT<br>did we pay<br>in&nbsp;January?”</p>
        <p class="text-faint mt-5 max-w-sm leading-relaxed">Upload your invoice export — and query the data in plain language. No formulas, no Excel exports.</p>
      </div>
      <div class="flex items-end justify-between">
        <div class="font-mono text-sm text-faint leading-relaxed">
          <div>01 — upload file</div>
          <div>02 — verify parsing</div>
          <div>03 — ask the AI</div>
        </div>
        <div class="stamp-seal text-sm font-semibold px-4 py-2.5">e-invoice<br>verified</div>
      </div>
    </div>
  </div>
</div>
</x-guest-layout>
