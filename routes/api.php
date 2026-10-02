<?php

use App\Http\Controllers\Api\ResultsApiController;
use App\Support\ApiAbilities;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

// Every route is prefixed /api and needs a Sanctum token (Settings → API tokens) sent as
// "Authorization: Bearer <token>"; each part of the API also needs its own ability.

// Read-only results + decision traces.
Route::middleware(['auth:sanctum', CheckAbilities::class.':'.ApiAbilities::RESULTS])->group(function () {
    Route::get('/results/{item}', [ResultsApiController::class, 'result'])->whereNumber('item');
    Route::get('/uploads/{batch}', [ResultsApiController::class, 'upload']);
});
