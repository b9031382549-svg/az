<?php

namespace App\Livewire;

use App\Support\ApiAbilities;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Component;

// API tokens on the Settings page: issue a token for an outside system, pick what it may do,
// see when it was last used, revoke it. Only the token's hash is stored, so the plain token
// is shown once, right after it is issued. A token belongs to the user who issued it, but
// every user sees and can revoke all of them — the API is a shared door, not a personal one.
class ApiTokens extends Component
{
    public string $name = '';

    /** @var array<int, string> the abilities ticked for the next token */
    public array $abilities = [];

    /** The token just issued, in plain text — shown once, never stored. */
    public ?string $plainToken = null;

    public function mount(): void
    {
        $this->abilities = ApiAbilities::all();
    }

    public function create(): void
    {
        $this->validate([
            'name' => 'required|string|max:100',
            'abilities' => 'required|array|min:1',
            'abilities.*' => 'in:'.implode(',', ApiAbilities::all()),
        ]);

        $issued = auth()->user()->createToken(trim($this->name), array_values($this->abilities));
        Audit::log('api_token.created', [
            'name' => $issued->accessToken->name,
            'abilities' => $issued->accessToken->abilities,
        ], $issued->accessToken);

        $this->plainToken = $issued->plainTextToken;
        $this->name = '';
        $this->abilities = ApiAbilities::all();
    }

    public function delete(int $id): void
    {
        $token = PersonalAccessToken::find($id);
        if ($token === null) {
            return;
        }

        Audit::log('api_token.deleted', ['name' => $token->name], $token);
        $token->delete();
        $this->plainToken = null;
    }

    public function dismissToken(): void
    {
        $this->plainToken = null;
    }

    public function render(): View
    {
        return view('livewire.api-tokens', [
            'tokens' => PersonalAccessToken::with('tokenable')->latest('id')->get(),
            'labels' => ApiAbilities::labels(),
        ]);
    }
}
