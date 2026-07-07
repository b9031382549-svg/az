<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ReviewExportController;
use App\Livewire\AskAi;
use App\Livewire\Benchmark;
use App\Livewire\Catalog;
use App\Livewire\ClassificationDecision;
use App\Livewire\Classify;
use App\Livewire\Invoices;
use App\Livewire\Logs;
use App\Livewire\ReviewQueue;
use App\Livewire\UploadInvoices;
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
    Route::get('/upload', UploadInvoices::class)->name('upload');

    // Task 2 — goods/services classifier
    Route::get('/classify', Classify::class)->name('classify');
    Route::get('/review', ReviewQueue::class)->name('review');
    Route::get('/review/export', ReviewExportController::class)->name('review.export');
    Route::get('/review/decision/{item}', ClassificationDecision::class)->name('review.decision');
    Route::get('/benchmark', Benchmark::class)->name('benchmark');
    Route::get('/catalog', Catalog::class)->name('catalog');

    Route::get('/settings', [PageController::class, 'settings'])->name('settings');
    Route::post('/locale', [LocaleController::class, 'update'])->name('locale.set');

    // Audit / activity log — reachable by URL only (not in the nav).
    Route::get('/log', Logs::class)->name('log');
});
