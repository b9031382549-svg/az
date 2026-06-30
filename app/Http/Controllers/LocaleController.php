<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /** Set the UI language (en/az/ru) for the current user + session. */
    public function update(Request $request): RedirectResponse
    {
        $locale = (string) $request->input('locale');

        if (in_array($locale, SetLocale::SUPPORTED, true)) {
            $request->session()->put('locale', $locale);
            if ($user = $request->user()) {
                $user->update(['locale' => $locale]);
            }
        }

        return back();
    }
}
