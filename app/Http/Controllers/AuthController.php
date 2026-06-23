<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // The placeholder admin account is stored with email = "admin".
        $ok = Auth::attempt(
            ['email' => $credentials['login'], 'password' => $credentials['password']],
            $request->boolean('remember'),
        );

        if (! $ok) {
            return back()
                ->withErrors(['login' => 'These credentials do not match our records.'])
                ->onlyInput('login');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('overview'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
