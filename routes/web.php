<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PageController;
use App\Livewire\AskAi;
use App\Livewire\Invoices;
use Illuminate\Support\Facades\Route;

// Guest
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Authenticated app
Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('overview'));
    Route::get('/overview', [DashboardController::class, 'index'])->name('overview');
    Route::get('/invoices', Invoices::class)->name('invoices');
    Route::get('/ask', AskAi::class)->name('ask');
    Route::get('/upload', [PageController::class, 'upload'])->name('upload');
    Route::get('/settings', [PageController::class, 'settings'])->name('settings');
});
