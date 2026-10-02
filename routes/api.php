<?php

use App\Http\Controllers\Api\ResultsApiController;
use App\Http\Controllers\Api\VersionController;
use App\Support\ApiAbilities;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

// Every route is prefixed /api and needs a Sanctum token (Settings → API tokens) sent as
// "Authorization: Bearer <token>"; the classifier and results parts also need their ability.

// Service endpoints: any valid token.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/version', VersionController::class);
});

// Read-only results + decision traces.
Route::middleware(['auth:sanctum', CheckAbilities::class.':'.ApiAbilities::RESULTS])->group(function () {
    Route::get('/results/{item}', [ResultsApiController::class, 'result'])->whereNumber('item');
    Route::get('/uploads/{batch}', [ResultsApiController::class, 'upload']);
});
