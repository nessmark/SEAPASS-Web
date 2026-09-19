<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Keep trip tab statuses current even when no admin browser is open.
 * Requires the system cron: * * * * * php artisan schedule:run
 */
Schedule::command('trips:sync-statuses')->everyMinute();

/*
 * Generate upcoming ferry trips automatically based on recurring rules (Alarm-Clock Engine).
 * Pre-populates rolling 90-day (at least 3 months) lookahead window.
 */
Schedule::command('schedules:generate-recurring --days=90')->dailyAt('00:00');

