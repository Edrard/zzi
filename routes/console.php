<?php

use App\Services\SettingsService;
use App\Services\Znuny\Cache\PrewarmRunnerService;
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

Schedule::command('znuny:evaluate-manual-ticket-lifecycle')->everyMinute()->withoutOverlapping();

$isTicketWorkspaceEnabled = true;
try {
    $isTicketWorkspaceEnabled = SettingsService::bool('znuny_ticket_workspace_enabled', true) ?? true;
} catch (Throwable $e) {
    report($e);
    $isTicketWorkspaceEnabled = false;
}

if ($isTicketWorkspaceEnabled) {
    Schedule::command('znuny:warm-ticket-workspace-cache --scheduled')->everyMinute()->withoutOverlapping();
    Schedule::command('znuny:sync-closed-ticket-cache')->everyMinute()->withoutOverlapping();
    Schedule::command('znuny:sync-closed-ticket-cache --full')->dailyAt('02:30')->withoutOverlapping();
}

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
    $mode = SettingsService::string('manual_ticket_auto_close_schedule_mode', null);
    if ($mode !== null) {
        $autoCloseMode = $mode;
    } else {
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

Schedule::command('scheduled-znuny:run')->everyMinute()->withoutOverlapping();

// Znuny Prewarm Datasets
$prewarmDatasets = [
    'queues' => ['key' => 'znuny_prewarm_queues_interval_minutes', 'default' => 5],
    'agents' => ['key' => 'znuny_prewarm_agents_interval_minutes', 'default' => 5],
    'customer_users' => ['key' => 'znuny_prewarm_customer_users_interval_minutes', 'default' => 30],
    'lookups' => ['key' => 'znuny_prewarm_lookups_interval_minutes', 'default' => 60],
];

foreach ($prewarmDatasets as $dataset => $config) {
    Schedule::call(function () use ($dataset, $config) {
        try {
            $interval = max(3, SettingsService::int($config['key'], $config['default']));
        } catch (Throwable $e) {
            $interval = max(3, $config['default']);
        }

        if ((intdiv(now()->timestamp, 60) % $interval) === 0) {
            app(PrewarmRunnerService::class)->run($dataset, 'scheduler');
        }
    })->everyMinute()->name('znuny-prewarm-'.$dataset);
}
