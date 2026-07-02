<?php

use App\Http\Controllers\Api\ResultsApiController;
use App\Http\Middleware\ApiKeyAuth;
use Illuminate\Support\Facades\Route;

// Read-only results API (key via RESULTS_API_KEY). All routes are prefixed /api.
Route::middleware(ApiKeyAuth::class)->group(function () {
    Route::get('/results/{item}', [ResultsApiController::class, 'result'])->whereNumber('item');
    Route::get('/uploads/{batch}', [ResultsApiController::class, 'upload']);
});
