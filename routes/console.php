<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Required system cron:
 * * * * * cd /var/www/work.vamark.com/http && php84 artisan schedule:run >> /dev/null 2>&1
 */

Schedule::command('app:poll-problems')->everyMinute();
Schedule::command('app:cleanup')->dailyAt('02:30');
Schedule::command('app:collect-daily-statistics')->dailyAt('23:55');
