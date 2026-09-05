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
