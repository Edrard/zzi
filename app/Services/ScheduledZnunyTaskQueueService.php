<?php

namespace App\Services;

use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Services\Cron\CronService;

class ScheduledZnunyTaskQueueService
{
    public function materializePendingRuns(): int
    {
        $now = now();
        $tasks = ScheduledZnunyTask::where('enabled', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', $now);
            })
            ->get();

        $count = 0;
        foreach ($tasks as $task) {
            $cronService = app(CronService::class);

            if (! $task->next_run_at) {
                // Initial run initialization
                $task->update(['next_run_at' => $cronService->calculateNextRunFrom($task->cron_expression, $now)]);

                continue;
            }

            $maxAgeDays = SettingsService::int('scheduled_tasks_missed_run_max_age_days', 30);
            $cutoff = $now->copy()->subDays($maxAgeDays);

            while ($task->next_run_at && $task->next_run_at <= $now) {
                if ($task->next_run_at >= $cutoff) {
                    ScheduledZnunyTaskRun::create([
                        'scheduled_znuny_task_id' => $task->id,
                        'task_name_snapshot' => $task->name,
                        'run_type' => 'scheduled',
                        'status' => 'pending',
                        'scheduled_for' => \Carbon\Carbon::parse($task->next_run_at)->utc()->toDateTimeString(),
                    ]);
                    $count++;
                }

                // Calculate and set next run time
                $task->next_run_at = $cronService->calculateNextRunFrom($task->cron_expression, $task->next_run_at);
            }
            $task->save();
        }

        return $count;
    }
}
