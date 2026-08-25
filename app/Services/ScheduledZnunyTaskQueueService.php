<?php

namespace App\Services;

use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Services\Cron\CronService;
use Carbon\Carbon;

class ScheduledZnunyTaskQueueService
{
    public function materializePendingRuns(): int
    {
        $now = now();
        $tasks = ScheduledZnunyTask::where('enabled', true)->get();

        $count = 0;
        $batchLimit = SettingsService::int('scheduled_tasks_catchup_batch_limit', 50);

        foreach ($tasks as $task) {
            $cronService = app(CronService::class);

            if (! $task->next_run_at) {
                // Initial run initialization
                $next = $cronService->calculateNextRunFrom($task->cron_expression, $now, $task->timezone);
                $task->update(['next_run_at' => $next ? $next->utc()->toDateTimeString() : null]);

                continue;
            }

            // Normalization for existing stale next_run_at values
            if ($task->last_run_at && $task->next_run_at) {
                $lastProcessedRun = ScheduledZnunyTaskRun::where('scheduled_znuny_task_id', $task->id)
                    ->where('run_type', 'scheduled')
                    ->whereIn('status', ['success', 'failed', 'uncertain', 'skipped'])
                    ->orderBy('scheduled_for', 'desc')
                    ->first();

                if ($lastProcessedRun && $lastProcessedRun->scheduled_for) {
                    $scheduledForUtc = Carbon::parse($lastProcessedRun->scheduled_for)->utc();
                    $staleValues = [
                        $scheduledForUtc->toDateTimeString(), // Not advanced at all
                        $scheduledForUtc->copy()->timezone($task->timezone)->format('Y-m-d H:i:s'), // Local time masquerading as UTC
                    ];

                    if (in_array($task->next_run_at->toDateTimeString(), $staleValues) || $task->next_run_at <= $task->last_run_at) {
                        $next = $cronService->calculateNextRunFrom($task->cron_expression, $scheduledForUtc, $task->timezone);
                        $task->next_run_at = $next ? $next->utc()->toDateTimeString() : null;
                        $task->save();
                    }
                }
            }

            $maxAgeDays = SettingsService::int('scheduled_tasks_missed_run_max_age_days', 30);
            $cutoff = $now->copy()->subDays($maxAgeDays);
            $runsForTask = 0;

            while ($task->next_run_at && $task->next_run_at <= $now) {
                if ($runsForTask >= $batchLimit) {
                    break;
                }

                if ($task->next_run_at >= $cutoff) {
                    $run = ScheduledZnunyTaskRun::firstOrCreate(
                        [
                            'scheduled_znuny_task_id' => $task->id,
                            'scheduled_for' => Carbon::parse($task->next_run_at)->utc()->toDateTimeString(),
                        ],
                        [
                            'task_name_snapshot' => $task->name,
                            'run_type' => 'scheduled',
                            'status' => 'pending',
                        ]
                    );

                    if ($run->wasRecentlyCreated) {
                        $count++;
                        $runsForTask++;
                    }
                }

                // Calculate and set next run time
                $task->next_run_at = $cronService->calculateNextRunFrom($task->cron_expression, $task->next_run_at, $task->timezone);
            }
            $task->save();
        }

        return $count;
    }
}
