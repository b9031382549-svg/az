<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Horizon throughput / wait-time metrics for the dashboard graphs.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Recover conflict items whose search-resolve claim was set but whose job was lost
// (crash between the Postgres claim and the Redis enqueue). No-op when the resolver is
// disabled. See App\Console\Commands\ReapSearchResolves.
Schedule::command('classify:reap-search-resolves')->everyFifteenMinutes()->withoutOverlapping();
