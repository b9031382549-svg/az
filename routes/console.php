<?php

use App\Jobs\GpuActionJob;
use App\Models\FinetuneRun;
use App\Models\GpuServer;
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

// Drive the A/B GPU state machine unattended. The tick runs as a QUEUED job on the WORKER —
// the nebius CLI + SSH key are mounted only there (the scheduler container has neither), so it
// must never run gpu:tick inline. Fire once a minute WHILE a slot is mid-lifecycle or a run is
// active; skip when idle (both slots off, no active run) so there are no perpetual no-op ticks.
// The /gpu-servers page drives ticks while open — this covers the page-closed case: a boot or
// training that must keep advancing, plus the idle-autostop + 24h-ceiling "forgot" safety.
Schedule::job(new GpuActionJob('tick'))
    ->everyMinute()
    ->when(fn () => GpuServer::whereNotIn('status', [GpuServer::STATUS_OFF, GpuServer::STATUS_ERROR])->exists()
        || FinetuneRun::whereNotIn('status', [FinetuneRun::STATUS_DONE, FinetuneRun::STATUS_FAILED])->exists());
