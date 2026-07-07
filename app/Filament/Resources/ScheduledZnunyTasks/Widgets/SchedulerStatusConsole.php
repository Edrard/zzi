<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Widgets;

use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\SystemAlert;
use App\Services\SchedulerSafetyService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

class SchedulerStatusConsole extends Widget
{
    protected string $view = 'filament.resources.scheduled-znuny-tasks.widgets.scheduler-status-console';

    protected int|string|array $columnSpan = 'full';

    public function getViewData(): array
    {
        $enabled = SettingsService::bool('scheduled_tasks_enabled', true);
        $pausedUntil = SettingsService::string('scheduled_tasks_paused_until');
        $pauseReason = SettingsService::string('scheduled_tasks_pause_reason');
        $disabledReason = SettingsService::string('scheduled_tasks_disabled_reason');

        $status = 'Enabled';
        if (! $enabled) {
            $status = 'Disabled';
        } elseif ($pausedUntil && Carbon::parse($pausedUntil)->isFuture()) {
            $status = 'Paused';
        }

        $pendingRuns = ScheduledZnunyTaskRun::where('status', 'pending')->count();
        $runningRuns = ScheduledZnunyTaskRun::where('status', 'running')->count();
        $successRuns = ScheduledZnunyTaskRun::where('status', 'success')->count();
        $skippedRuns = ScheduledZnunyTaskRun::where('status', 'skipped')->count();
        $failedRuns = ScheduledZnunyTaskRun::where('status', 'failed')->count();
        $uncertainRuns = ScheduledZnunyTaskRun::where('status', 'uncertain')->count();

        $lastProcessed = ScheduledZnunyTaskRun::whereNotNull('finished_at')->orderBy('finished_at', 'desc')->first()?->finished_at;
        $nextDue = ScheduledZnunyTask::where('enabled', true)->orderBy('next_run_at', 'asc')->first()?->next_run_at;

        $lastActiveAlert = SystemAlert::where('status', 'active')->where('source', 'scheduler')->latest()->first();

        return [
            'schedulerStatus' => $status,
            'pendingRuns' => $pendingRuns,
            'runningRuns' => $runningRuns,
            'successRuns' => $successRuns,
            'skippedRuns' => $skippedRuns,
            'failedRuns' => $failedRuns,
            'uncertainRuns' => $uncertainRuns,
            'lastProcessed' => $lastProcessed,
            'nextDue' => $nextDue,
            'pausedUntil' => $pausedUntil,
            'pauseReason' => $pauseReason,
            'disabledReason' => $disabledReason,
            'lastActiveAlert' => $lastActiveAlert,
        ];
    }

    public function enableScheduler(): void
    {
        app(SchedulerSafetyService::class)->enableScheduler();
        Notification::make()->title('Scheduler Enabled')->success()->send();
    }

    public function disableScheduler(): void
    {
        app(SchedulerSafetyService::class)->disableScheduler('Manually disabled by admin');
        Notification::make()->title('Scheduler Disabled')->warning()->send();
    }

    public function pauseScheduler(): void
    {
        app(SchedulerSafetyService::class)->pauseScheduler('Manually paused by admin');
        Notification::make()->title('Scheduler Paused')->warning()->send();
    }

    public function clearPause(): void
    {
        app(SchedulerSafetyService::class)->clearPause();
        Notification::make()->title('Pause Cleared')->success()->send();
    }
}
