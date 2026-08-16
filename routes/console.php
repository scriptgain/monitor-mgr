<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The poller. Every minute it queues a check for each monitor whose interval
// has elapsed, and marks heartbeat monitors that have gone quiet. This is the
// job that makes the product monitor anything: without `schedule:run` in cron
// AND a running `queue:work`, no check is ever executed.
Schedule::command('monitor:poll')->everyMinute()->withoutOverlapping();

// Housekeeping sweep. The command enforces the configured maintenance window,
// so it is safe to attempt hourly (installer wires `schedule:run` cron).
Schedule::command('monitor:maintenance')->hourly()->withoutOverlapping();

// Periodic ONLINE license validation against ScriptGain (~every 2 days).
// Verifies the signed /v1/validate response and drives the same lockdown as the
// offline .lic path. Safe to run when no key is configured (it no-ops).
Schedule::command('license:check-online')->cron('37 3 */2 * *')->withoutOverlapping();

// Self-update: nightly auto-apply of newer signed releases (opt-out), plus an
// admin "Update Now" request the scheduler services within a minute.
Schedule::command('app:update')->everyFiveMinutes()->when(fn () => \App\Services\UpdateService::autoEnabled())->withoutOverlapping();
Schedule::command('app:update')->everyMinute()->when(fn () => \App\Models\Setting::get('update_requested') === '1')->withoutOverlapping();

// Automated remote database backup (self-gates on enabled flag + frequency);
// plus an admin "Run Backup Now" request serviced within a minute.
Schedule::command('db-backup:run')->dailyAt(rescue(fn () => \App\Models\Setting::get('dbbackup_time'), null, false) ?: '02:30')->withoutOverlapping();
Schedule::command('db-backup:run --force')->everyMinute()->when(fn () => \App\Models\Setting::get('dbbackup_requested') === '1')->withoutOverlapping();
