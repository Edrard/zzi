<?php

namespace App\Services;

use App\Models\ScheduledZnunyTaskRun;
use App\Services\Znuny\ScheduledTicketCreationOutcome;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScheduledZnunyTaskRunProcessor
{
    public function __construct(
        private SystemAlertService $alertService,
        private ScheduledZnunyTicketCreationService $ticketService,
        private SchedulerSafetyService $safetyService,
        private MailNotificationService $mailService
    ) {}

    public function processNextBatch(int $limit, int $maxRuntimeSeconds): int
    {
        $startTime = microtime(true);
        $processedCount = 0;

        $runs = ScheduledZnunyTaskRun::where('status', 'pending')
            ->orderBy('scheduled_for', 'asc')
            ->orderBy('id', 'asc')
            ->take($limit)
            ->get();

        foreach ($runs as $run) {
            if ((microtime(true) - $startTime) >= $maxRuntimeSeconds) {
                break;
            }

            $success = $this->processRun($run);
            $processedCount++;

            if (! $success) {
                // Stop processing batch on non-success outcomes that pause/disable
                break;
            }
        }

        return $processedCount;
    }

    private function processRun(ScheduledZnunyTaskRun $run): bool
    {
        $startedAt = now('UTC');
        // Use conditional update to ensure another process didn't grab it
        $updated = ScheduledZnunyTaskRun::where('id', $run->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'running',
                'started_at' => $startedAt->toDateTimeString(),
            ]);

        if (! $updated) {
            return true; // Already processed
        }

        $run->refresh();

        try {
            $task = $run->task;
            if (! $task) {
                throw new \Exception('Run is missing associated task.');
            }

            $result = $this->ticketService->createTicketFromTask($task);
            $finishedAt = now('UTC');
            $durationMs = $startedAt->diffInMilliseconds($finishedAt);

            switch ($result['outcome']) {
                case ScheduledTicketCreationOutcome::SUCCESS:
                    $run->update([
                        'status' => 'success',
                        'finished_at' => $finishedAt->toDateTimeString(),
                        'duration_ms' => $durationMs,
                        'ticket_id' => $result['ticket_id'],
                        'ticket_number' => $result['ticket_number'],
                        'response_snapshot' => $result['response_snapshot'] ?? null,
                        'error_summary' => null,
                        'error_details' => null,
                    ]);

                    $task->update([
                        'last_run_at' => $finishedAt->toDateTimeString(),
                        'last_success_at' => $finishedAt->toDateTimeString(),
                        'last_status' => 'success',
                        'last_ticket_id' => $result['ticket_id'],
                        'last_ticket_number' => $result['ticket_number'],
                        'last_error_summary' => null,
                    ]);

                    Cache::forget('scheduled_tasks_consecutive_failures');

                    return true;

                case ScheduledTicketCreationOutcome::NOT_SENT:
                    // Revert to pending
                    $run->update([
                        'status' => 'pending',
                        'started_at' => null,
                    ]);

                    $reason = 'Pre-flight/Local check failed: '.$result['error_summary'];
                    $this->safetyService->pauseScheduler($reason);

                    $this->alertService->warning(
                        'scheduler',
                        'Scheduler Paused (Not Sent)',
                        "Task '{$run->task_name_snapshot}' paused the scheduler. {$reason}"
                    );
                    $this->mailService->sendWarning('Scheduler Paused (Not Sent)', "Task '{$run->task_name_snapshot}' paused the scheduler.\nReason: {$reason}\nDetails:\n".($result['error_details'] ?? ''));

                    return false;

                case ScheduledTicketCreationOutcome::FAILED:
                    $run->update([
                        'status' => 'failed',
                        'finished_at' => $finishedAt->toDateTimeString(),
                        'duration_ms' => $durationMs,
                        'error_summary' => $result['error_summary'],
                        'error_details' => $result['error_details'],
                        'response_snapshot' => $result['response_snapshot'] ?? null,
                    ]);

                    $task->update([
                        'last_run_at' => $finishedAt->toDateTimeString(),
                        'last_failure_at' => $finishedAt->toDateTimeString(),
                        'last_status' => 'failed',
                        'last_error_summary' => $result['error_summary'],
                    ]);

                    $failures = Cache::increment('scheduled_tasks_consecutive_failures');
                    $autoDisable = SettingsService::bool('scheduled_tasks_auto_disable_on_failures', true);
                    $threshold = SettingsService::int('scheduled_tasks_failure_threshold', 3);

                    if ($autoDisable && $failures >= $threshold) {
                        $reason = "Reached {$threshold} consecutive failures.";
                        $this->safetyService->disableScheduler($reason);
                        $this->alertService->danger('scheduler', 'Scheduler Disabled (Failure Threshold)', "Disabled after task '{$run->task_name_snapshot}' failed. Reason: {$reason}");
                        $this->mailService->sendAlarm('Scheduler Disabled (Failure Threshold)', "Disabled after task '{$run->task_name_snapshot}' failed.\nError: ".$result['error_summary']."\nDetails:\n".$result['error_details']);

                        return false;
                    }

                    return true;

                case ScheduledTicketCreationOutcome::UNCERTAIN:
                    // The create request may have reached Znuny; mark uncertain and disable scheduler to prevent duplicate tickets.
                    $run->update([
                        'status' => 'uncertain',
                        'finished_at' => $finishedAt->toDateTimeString(),
                        'duration_ms' => $durationMs,
                        'error_summary' => $result['error_summary'],
                        'error_details' => $result['error_details'],
                        'response_snapshot' => $result['response_snapshot'] ?? null,
                    ]);

                    $task->update([
                        'last_run_at' => $finishedAt->toDateTimeString(),
                        'last_failure_at' => $finishedAt->toDateTimeString(),
                        'last_status' => 'uncertain',
                        'last_error_summary' => $result['error_summary'],
                    ]);

                    $reason = 'Uncertain outcome from Znuny API: '.$result['error_summary'];
                    $this->safetyService->disableScheduler($reason);

                    $this->alertService->danger(
                        'scheduler',
                        'Scheduler Disabled (Uncertain Outcome)',
                        "Task '{$run->task_name_snapshot}' caused an uncertain outcome. To prevent duplicate tickets, the scheduler is immediately disabled. {$reason}"
                    );
                    $this->mailService->sendAlarm('Scheduler Disabled (Uncertain Outcome)', "Task '{$run->task_name_snapshot}' caused an uncertain outcome. The scheduler has been disabled immediately to prevent duplicate tickets.\nReason: {$reason}\nDetails:\n".($result['error_details'] ?? ''));

                    return false;
            }
        } catch (\Throwable $e) {
            $finishedAt = now('UTC');
            Log::error('Scheduled task run processor exception: '.$e->getMessage(), ['run_id' => $run->id]);

            $run->update([
                'status' => 'failed',
                'finished_at' => $finishedAt->toDateTimeString(),
                'duration_ms' => isset($startedAt) ? $startedAt->diffInMilliseconds($finishedAt) : null,
                'error_summary' => substr($e->getMessage(), 0, 255),
                'error_details' => $e->getMessage()."\n".$e->getTraceAsString(),
            ]);

            if ($run->task) {
                $run->task->update([
                    'last_run_at' => $finishedAt->toDateTimeString(),
                    'last_status' => 'failed',
                    'last_failure_at' => $finishedAt->toDateTimeString(),
                    'last_error_summary' => substr($e->getMessage(), 0, 255),
                ]);
            }

            // Cache is used for phase 3A consecutive failures.
            // TODO: Move consecutive failure counter to settings if persistence across cache restarts is required.
            $failures = Cache::increment('scheduled_tasks_consecutive_failures');
            $autoDisable = SettingsService::bool('scheduled_tasks_auto_disable_on_failures', true);
            $threshold = SettingsService::int('scheduled_tasks_failure_threshold', 3);

            if ($autoDisable && $failures >= $threshold) {
                $reason = "Reached {$threshold} consecutive failures.";
                $this->safetyService->disableScheduler($reason);
                $this->alertService->danger('scheduler', 'Scheduler Disabled (Failure Threshold)', "Disabled after task '{$run->task_name_snapshot}' failed. Reason: {$reason}");
                $this->mailService->sendAlarm('Scheduler Disabled (Failure Threshold)', "Disabled after task '{$run->task_name_snapshot}' failed.\nError: ".$e->getMessage());

                return false;
            }

            return true;
        }

        return true;
    }
}
