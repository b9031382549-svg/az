<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the UI language for the request: the signed-in user's saved preference,
 * else a guest's session choice, else the app default (en). Supported: en/az/ru.
 */
class SetLocale
{
    public const SUPPORTED = ['en', 'az', 'ru'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = $request->session()->get('locale');
        }
        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = config('app.locale', 'en');
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
