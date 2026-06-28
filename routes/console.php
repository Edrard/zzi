<?php

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Required system cron:
 * * * * * cd /path/to/project/http && php84 artisan schedule:run >> /dev/null 2>&1
 */

Schedule::command('app:poll-zabbix-problems')->everyMinute();
Schedule::command('app:cleanup')->dailyAt('02:30');
Schedule::command('app:collect-daily-statistics')->dailyAt('23:55');
Schedule::command('znuny:evaluate-manual-ticket-lifecycle')->everyMinute()->withoutOverlapping();
Schedule::command('znuny:warm-ticket-workspace-cache --scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('znuny:sync-closed-ticket-cache')->everyMinute()->withoutOverlapping();
Schedule::command('znuny:sync-closed-ticket-cache --full')->dailyAt('02:30')->withoutOverlapping();

$syncInterval = 5;

try {
    $syncInterval = SettingsService::int('znuny_linked_ticket_sync_interval_minutes', 5);
} catch (Throwable $e) {
    // Database or settings table might not exist yet during testing or initial migrations
}

// Use default if null or strictly less than 0
if ($syncInterval === null || $syncInterval < 0) {
    $syncInterval = 5;
}

if ($syncInterval > 0) {
    // Avoid cron string exceptions if interval > 59 by clamping it
    // Wait, in Laravel ->cron("*/5 * * * *") works for 5.
    // If it's over 59, it's safer to just clamp it or use ->everyNMinutes() if we want.
    // But Laravel doesn't have an arbitrary everyNMinutes.
    // We can just use cron safely clamped to 59, or use modulo logic.
    $interval = min($syncInterval, 59);
    Schedule::command('znuny:sync-linked-tickets')
        ->cron("*/{$interval} * * * *")
        ->withoutOverlapping();
}

$autoCloseMode = 'execute';
try {
    $autoCloseMode = SettingsService::string('manual_ticket_auto_close_schedule_mode', 'execute');
    // Migration fallback for environments without the new setting but having the old one.
    if (! Setting::where('key', 'manual_ticket_auto_close_schedule_mode')->exists()) {
        $oldEnabled = SettingsService::bool('manual_ticket_auto_close_enabled', true);
        $autoCloseMode = $oldEnabled ? 'execute' : 'disabled';
    }
} catch (Throwable $e) {
    // Database or settings table might not exist yet
}

if ($autoCloseMode === 'dry_run' && $syncInterval > 0) {
    $interval = min($syncInterval, 59);
    Schedule::command('znuny:auto-close-manual-tickets')
        ->cron("*/{$interval} * * * *")
        ->withoutOverlapping();
} elseif ($autoCloseMode === 'execute' && $syncInterval > 0) {
    $interval = min($syncInterval, 59);
    Schedule::command('znuny:auto-close-manual-tickets --execute')
        ->cron("*/{$interval} * * * *")
        ->withoutOverlapping();
}
