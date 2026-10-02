<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Api\HealthCheck;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * GET /api/health-check — 200 "Health check passed" when every part the classifier needs is
 * up; otherwise 500 and which parts are not (plain text, as the client's other services answer).
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): Response
    {
        $failed = $health->failures();
        if ($failed === []) {
            return response('Health check passed', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        Log::warning('health.failed', $failed);
        $why = collect($failed)->map(fn (string $problem, string $check) => "{$check}: {$problem}")->implode('; ');

        return response('Health check failed — '.$why, 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
