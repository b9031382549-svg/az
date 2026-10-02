<?php

use App\Http\Controllers\Api\ClassifyController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ResultsApiController;
use App\Http\Controllers\Api\VersionController;
use App\Support\ApiAbilities;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

// Every route is prefixed /api and needs a Sanctum token (Settings → API tokens) sent as
// "Authorization: Bearer <token>"; the classifier and results parts also need their ability.

// Service endpoints: any valid token.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/health-check', HealthController::class);
    Route::get('/version', VersionController::class);
});

// The classifier: send items, then ask for the request by its id.
Route::middleware(['auth:sanctum', CheckAbilities::class.':'.ApiAbilities::CLASSIFY])->group(function () {
    Route::post('/classify', [ClassifyController::class, 'store']);
    Route::get('/classify/{id}', [ClassifyController::class, 'show'])->whereUuid('id');
});

// Read-only results + decision traces.
Route::middleware(['auth:sanctum', CheckAbilities::class.':'.ApiAbilities::RESULTS])->group(function () {
    Route::get('/results/{item}', [ResultsApiController::class, 'result'])->whereNumber('item');
    Route::get('/uploads/{batch}', [ResultsApiController::class, 'upload']);
});
