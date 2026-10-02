<div class="card p-6 mt-5">
  <h2 class="font-display text-xl mb-1">{{ __('API tokens') }}</h2>
  <p class="text-muted text-sm mb-5">{{ __('For outside systems that call the API. A token is shown once, right after it is created — copy it then.') }}</p>

  @if($plainToken)
    <div class="card-flat p-4 mb-5" x-data="{ copied: false }">
      <p class="font-medium mb-2">{{ __('Copy the token now — it will not be shown again.') }}</p>
      <div class="flex flex-wrap items-center gap-2">
        <input x-ref="token" class="field-input font-mono text-sm flex-1 min-w-0" value="{{ $plainToken }}" readonly @focus="$el.select()">
        {{-- execCommand, not navigator.clipboard: the clipboard API needs HTTPS, and prod is plain HTTP. --}}
        <button type="button" class="btn btn-ink btn-sm" @click="$refs.token.select(); document.execCommand('copy'); copied = true">
          <span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
        </button>
        <button type="button" class="btn btn-ghost btn-sm" wire:click="dismissToken">{{ __('Close') }}</button>
      </div>
    </div>
  @endif

  <form wire:submit="create" class="space-y-4">
    <div>
      <label class="field-label" for="token-name">{{ __('Token name') }}</label>
      <input id="token-name" wire:model="name" class="field-input" maxlength="100" placeholder="{{ __('e.g. the client integration') }}">
      @error('name') <p class="text-sm text-stamp mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
      <p class="field-label">{{ __('Allowed') }}</p>
      @foreach($labels as $ability => $label)
        <label class="flex items-center gap-2 text-sm py-0.5">
          <input type="checkbox" wire:model="abilities" value="{{ $ability }}"> {{ $label }}
        </label>
      @endforeach
      @error('abilities') <p class="text-sm text-stamp mt-1">{{ $message }}</p> @enderror
    </div>
    <button type="submit" class="btn btn-ink btn-sm">{{ __('Create token') }}</button>
  </form>

  <div class="mt-6">
    @if($tokens->isEmpty())
      <p class="text-muted text-sm">{{ __('No tokens yet.') }}</p>
    @else
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-muted border-b hair">
              <th class="font-medium py-2 pr-4">{{ __('Token name') }}</th>
              <th class="font-medium py-2 pr-4">{{ __('Allowed') }}</th>
              <th class="font-medium py-2 pr-4">{{ __('Created') }}</th>
              <th class="font-medium py-2 pr-4">{{ __('Last used') }}</th>
              <th class="py-2"></th>
            </tr>
          </thead>
          <tbody>
            @foreach($tokens as $token)
              <tr wire:key="token-{{ $token->id }}" class="border-b hair last:border-0 align-top">
                <td class="py-2.5 pr-4 font-medium break-words">{{ $token->name }}</td>
                <td class="py-2.5 pr-4">{{ collect($token->abilities)->map(fn ($a) => $labels[$a] ?? $a)->implode(', ') }}</td>
                <td class="py-2.5 pr-4 whitespace-nowrap text-muted">
                  {{ $token->created_at?->format('Y-m-d H:i') }}
                  <div class="text-faint text-xs">{{ $token->tokenable?->name ?? '—' }}</div>
                </td>
                <td class="py-2.5 pr-4 whitespace-nowrap text-muted">{{ $token->last_used_at?->diffForHumans() ?? __('Never') }}</td>
                <td class="py-2.5 text-right">
                  <button type="button" wire:click="delete({{ $token->id }})"
                          wire:confirm="{{ __('Delete this token? Systems using it lose access at once.') }}"
                          class="btn btn-ghost btn-sm text-stamp">{{ __('Delete') }}</button>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
</div>
