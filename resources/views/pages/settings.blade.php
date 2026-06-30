<x-app-layout :title="__('Settings').' · '.config('app.name')">
<section class="p-5 sm:p-8 max-w-[760px]">
  <div class="mb-7">
    <p class="kicker mb-1.5">{{ __('Account') }}</p>
    <h1 class="font-display text-4xl">{{ __('Settings') }}</h1>
  </div>

  <div class="card p-6">
    <h2 class="font-display text-xl mb-5">{{ __('Profile') }}</h2>
    <div class="space-y-5">
      <div>
        <label class="field-label">{{ __('Name') }}</label>
        <input class="field-input" value="{{ auth()->user()->name }}" disabled>
      </div>
      <div>
        <label class="field-label">{{ __('Login') }}</label>
        <input class="field-input" value="{{ auth()->user()->email }}" disabled>
      </div>
    </div>
  </div>

  <div class="card-flat p-5 mt-5 flex items-center justify-between">
    <div>
      <p class="font-medium">{{ __('Session') }}</p>
      <p class="text-muted text-sm">{{ __('Sign out of this device.') }}</p>
    </div>
    <form method="POST" action="{{ route('logout') }}">@csrf
      <button class="btn btn-ghost btn-sm">{{ __('Sign out') }}</button>
    </form>
  </div>
</section>
</x-app-layout>
