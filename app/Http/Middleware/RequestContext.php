<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a stable request_id to every request and shares it with the logger,
 * the views (shown in the footer) and the response header — so a single user
 * action can be correlated across the audit trail, the LLM log and the app log
 * when investigating a bug report.
 */
class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();

        app()->instance('request_id', $id);
        Log::shareContext(['request_id' => $id, 'user_id' => auth()->id()]);
        View::share('requestId', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
