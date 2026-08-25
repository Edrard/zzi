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

        $statusRaw = 'enabled';
        if (! $enabled) {
            $statusRaw = 'disabled';
        } elseif ($pausedUntil && Carbon::parse($pausedUntil)->isFuture()) {
            $statusRaw = 'paused';
        }

        $status = __('scheduled_znuny_tasks.scheduler.'.$statusRaw);

        $pendingRuns = ScheduledZnunyTaskRun::where('status', 'pending')->count();
        $runningRuns = ScheduledZnunyTaskRun::where('status', 'running')->count();
        $successRuns = ScheduledZnunyTaskRun::where('status', 'success')->count();
        $skippedRuns = ScheduledZnunyTaskRun::where('status', 'skipped')->count();
        $failedRuns = ScheduledZnunyTaskRun::where('status', 'failed')->count();
        $duplicateRuns = ScheduledZnunyTaskRun::where('status', 'duplicate')->count();
        $uncertainRuns = ScheduledZnunyTaskRun::where('status', 'uncertain')->count();

        $lastProcessedRun = ScheduledZnunyTaskRun::with('task')->whereNotNull('finished_at')->orderBy('finished_at', 'desc')->first();
        $lastProcessed = $lastProcessedRun?->finished_at;
        $lastProcessedTz = $lastProcessedRun?->task?->timezone ?? config('app.timezone');

        $nextDueTask = ScheduledZnunyTask::where('enabled', true)->orderBy('next_run_at', 'asc')->first();
        $nextDue = $nextDueTask?->next_run_at;
        $nextDueTz = $nextDueTask?->timezone ?? config('app.timezone');

        $lastActiveAlert = null;
        if ($statusRaw !== 'enabled') {
            $lastActiveAlert = SystemAlert::where('status', 'active')->where('source', 'scheduler')->latest()->first();
        }

        return [
            'schedulerStatus' => $status,
            'schedulerStatusRaw' => $statusRaw,
            'pendingRuns' => $pendingRuns,
            'runningRuns' => $runningRuns,
            'successRuns' => $successRuns,
            'skippedRuns' => $skippedRuns,
            'failedRuns' => $failedRuns,
            'duplicateRuns' => $duplicateRuns,
            'uncertainRuns' => $uncertainRuns,
            'lastProcessed' => $lastProcessed,
            'lastProcessedTz' => $lastProcessedTz,
            'nextDue' => $nextDue,
            'nextDueTz' => $nextDueTz,
            'pausedUntil' => $pausedUntil,
            'pauseReason' => $pauseReason,
            'disabledReason' => $disabledReason,
            'lastActiveAlert' => $lastActiveAlert,
        ];
    }

    public function enableScheduler(): void
    {
        abort_unless(auth()->user()?->canAdministerApplication(), 403);
        app(SchedulerSafetyService::class)->enableScheduler();
        Notification::make()->title(__('scheduled_znuny_tasks.scheduler.notifications.enabled'))->success()->send();
    }

    public function disableScheduler(): void
    {
        abort_unless(auth()->user()?->canAdministerApplication(), 403);
        app(SchedulerSafetyService::class)->disableScheduler('Manually disabled by admin');
        Notification::make()->title(__('scheduled_znuny_tasks.scheduler.notifications.disabled'))->warning()->send();
    }

    public function pauseScheduler(): void
    {
        abort_unless(auth()->user()?->canAdministerApplication(), 403);
        app(SchedulerSafetyService::class)->pauseScheduler('Manually paused by admin');
        Notification::make()->title(__('scheduled_znuny_tasks.scheduler.notifications.paused'))->warning()->send();
    }

    public function clearPause(): void
    {
        abort_unless(auth()->user()?->canAdministerApplication(), 403);
        app(SchedulerSafetyService::class)->clearPause();
        Notification::make()->title(__('scheduled_znuny_tasks.scheduler.notifications.resumed'))->success()->send();
    }
}
