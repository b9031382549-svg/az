<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Api\ModelVersion;
use Illuminate\Http\JsonResponse;

/** GET /api/version — the classifier's release version and the models it runs on. */
class VersionController extends Controller
{
    public function __invoke(ModelVersion $version): JsonResponse
    {
        return response()->json($version->toArray());
    }
}
