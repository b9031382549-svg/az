<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the read-only results API with a static key from config
 * (services.results_api.key <- RESULTS_API_KEY). Accepts the key as a Bearer
 * token or an X-Api-Key header. Fails closed: if no key is configured, every
 * request is rejected.
 */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.results_api.key', '');
        $provided = $request->bearerToken() ?: (string) $request->header('X-Api-Key', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid or missing API key.');
        }

        return $next($request);
    }
}
