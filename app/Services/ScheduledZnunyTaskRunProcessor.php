<?php

namespace App\Services;

use App\Models\ScheduledZnunyTaskRun;
use App\Services\Znuny\ScheduledTicketCreationOutcome;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\DB;

class ScheduledZnunyTaskRunProcessor
{
    public function __construct(
        private SystemAlertService $alertService,
        private ScheduledZnunyTicketCreationService $ticketService,
        private SchedulerSafetyService $safetyService,
        private MailNotificationService $mailService,
        private AuditLogger $auditLogger
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

        $confirmedSuccess = false;
        try {
            $task = $run->task;
            if (! $task) {
                throw new \Exception('Run is missing associated task.');
            }

            $result = $this->ticketService->createTicketFromTask($task, $run->id);
            $finishedAt = now('UTC');
            $durationMs = $startedAt->diffInMilliseconds($finishedAt);

            switch ($result['outcome']) {
                case ScheduledTicketCreationOutcome::SUCCESS:
                    $confirmedSuccess = true;
                    $run->update([
                        'status' => 'success',
                        'finished_at' => $finishedAt->toDateTimeString(),
                        'duration_ms' => $durationMs,
                        'ticket_id' => $result['ticket_id'],
                        'ticket_number' => $result['ticket_number'],
                        'response_snapshot' => $result['response_snapshot'] ?? null,
                        'payload_snapshot' => $result['payload_snapshot'] ?? null,
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
                    $run->update([
                        'status' => 'failed',
                        'finished_at' => $finishedAt->toDateTimeString(),
                        'duration_ms' => $durationMs,
                        'error_summary' => $result['error_summary'],
                        'error_details' => $result['error_details'],
                        'response_snapshot' => null,
                        'payload_snapshot' => null,
                    ]);

                    $task->update([
                        'last_run_at' => $finishedAt->toDateTimeString(),
                        'last_failure_at' => $finishedAt->toDateTimeString(),
                        'last_status' => 'failed',
                        'last_error_summary' => $result['error_summary'],
                    ]);

                    // Do not increment global failure threshold for local pre-flight missing config errors
                    // Do not pause the global scheduler

                    return true;

                case ScheduledTicketCreationOutcome::FAILED:
                    $run->update([
                        'status' => 'failed',
                        'finished_at' => $finishedAt->toDateTimeString(),
                        'duration_ms' => $durationMs,
                        'error_summary' => $result['error_summary'],
                        'error_details' => $result['error_details'],
                        'response_snapshot' => $result['response_snapshot'] ?? null,
                        'payload_snapshot' => $result['payload_snapshot'] ?? null,
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
                    $postCommitContext = null;

                    $processorResult = DB::transaction(function () use ($run, $task, $result, $finishedAt, $durationMs, &$postCommitContext) {
                        $lockedRun = ScheduledZnunyTaskRun::whereKey($run->id)->lockForUpdate()->first();

                        if (!$lockedRun || in_array($lockedRun->status, ['uncertain', 'success', 'failed'])) {
                            return false;
                        }

                        // The create request may have reached Znuny; mark uncertain and disable scheduler to prevent duplicate tickets.
                        $runUpdate = [
                            'status' => 'uncertain',
                            'finished_at' => $finishedAt->toDateTimeString(),
                            'duration_ms' => $durationMs,
                            'error_summary' => $result['error_summary'],
                            'error_details' => $result['error_details'] ?? '',
                            'response_snapshot' => $result['response_snapshot'] ?? null,
                            'payload_snapshot' => $result['payload_snapshot'] ?? null,
                        ];

                        $taskUpdate = [
                            'last_run_at' => $finishedAt->toDateTimeString(),
                            'last_failure_at' => $finishedAt->toDateTimeString(),
                            'last_status' => 'uncertain',
                            'last_error_summary' => $result['error_summary'],
                        ];

                        if (array_key_exists('ticket_id', $result) && $result['ticket_id'] !== null) {
                            $runUpdate['ticket_id'] = $result['ticket_id'];
                            $taskUpdate['last_ticket_id'] = $result['ticket_id'];
                        }

                        if (array_key_exists('ticket_number', $result) && $result['ticket_number'] !== null && trim((string) $result['ticket_number']) !== '') {
                            $runUpdate['ticket_number'] = $result['ticket_number'];
                            $taskUpdate['last_ticket_number'] = $result['ticket_number'];
                        }

                        $lockedRun->update($runUpdate);
                        $task->update($taskUpdate);

                        $reason = 'Uncertain outcome from Znuny API: '.$result['error_summary'];
                        $this->safetyService->disableScheduler($reason);

                        $postCommitContext = [
                            'attempt_id' => $result['attempt_id'] ?? null,
                            'ticket_id' => $result['ticket_id'] ?? null,
                            'ticket_number' => $result['ticket_number'] ?? null,
                            'error_summary' => $result['error_summary'],
                            'error_details' => $result['error_details'] ?? '',
                        ];

                        return false;
                    });

                    if ($postCommitContext !== null) {
                        $reason = 'Uncertain outcome from Znuny API: '.$postCommitContext['error_summary'];

                        try {
                            $this->auditLogger->log(
                                'scheduled_znuny_run_uncertain',
                                'ScheduledZnunyTaskRun',
                                $run->id,
                                [
                                    'task_id' => $run->scheduled_znuny_task_id,
                                    'task_name' => $run->task_name_snapshot,
                                    'attempt_id' => $postCommitContext['attempt_id'],
                                    'ticket_id' => $postCommitContext['ticket_id'],
                                    'ticket_number' => $postCommitContext['ticket_number'],
                                    'reason' => mb_substr($reason, 0, 255),
                                    'scheduler_disabled' => true,
                                ]
                            );
                        } catch (\Throwable $e) {
                            Log::error('Scheduled Znuny uncertain side effect failed: audit log; run ID ' . $run->id);
                        }

                        try {
                            $this->alertService->danger(
                                'scheduler',
                                'Scheduler Disabled (Uncertain Outcome)',
                                "Task '{$run->task_name_snapshot}' caused an uncertain outcome. To prevent duplicate tickets, the scheduler is immediately disabled. {$reason}"
                            );
                        } catch (\Throwable $e) {
                            Log::error('Scheduled Znuny uncertain side effect failed: danger alert; run ID ' . $run->id);
                        }

                        try {
                            $this->mailService->sendAlarm('Scheduler Disabled (Uncertain Outcome)', "Task '{$run->task_name_snapshot}' caused an uncertain outcome. The scheduler has been disabled immediately to prevent duplicate tickets.\nReason: {$reason}\nDetails:\n".$postCommitContext['error_details']);
                        } catch (\Throwable $e) {
                            Log::error('Scheduled Znuny uncertain side effect failed: mail alarm; run ID ' . $run->id);
                        }

                        try {
                            $message = "Task '{$run->task_name_snapshot}' (Run ID: {$run->id}) requires manual review.\n";
                            $message .= "The Znuny request outcome is uncertain.\n";
                            $message .= "Automatic resending is blocked. The administrator must verify Znuny manually.\n";
                            $message .= "Scheduler is currently disabled by the existing safety mechanism.\n";
                            $message .= "Attempt ID: " . ($postCommitContext['attempt_id'] ?? 'None') . "\n";
                            $message .= "Ticket ID: " . ($postCommitContext['ticket_id'] ?? 'None') . "\n";
                            $message .= "Ticket Number: " . ($postCommitContext['ticket_number'] ?? 'None');

                            $this->alertService->warning('scheduler', 'Scheduled Znuny ticket creation requires review', $message);
                        } catch (\Throwable $e) {
                            Log::error('Scheduled Znuny uncertain side effect failed: review warning; run ID ' . $run->id);
                        }
                    }

                    return $processorResult;
            }
        } catch (\Throwable $e) {
            $finishedAt = now('UTC');
            Log::error('Scheduled task run processor exception: '.$e->getMessage(), ['run_id' => $run->id]);

            if (isset($confirmedSuccess) && $confirmedSuccess) {
                try {
                    $run->update([
                        'status' => 'success',
                        'finished_at' => $finishedAt->toDateTimeString(),
                        'duration_ms' => isset($startedAt) ? $startedAt->diffInMilliseconds($finishedAt) : null,
                        'ticket_id' => $result['ticket_id'] ?? null,
                        'ticket_number' => $result['ticket_number'] ?? null,
                    ]);
                } catch (\Throwable $inner) {
                    Log::error('Failed to preserve run success state: '.$inner->getMessage(), ['run_id' => $run->id]);
                }

                if (isset($task)) {
                    try {
                        $task->update([
                            'last_run_at' => $finishedAt->toDateTimeString(),
                            'last_success_at' => $finishedAt->toDateTimeString(),
                            'last_status' => 'success',
                            'last_ticket_id' => $result['ticket_id'] ?? null,
                            'last_ticket_number' => $result['ticket_number'] ?? null,
                        ]);
                    } catch (\Throwable $inner) {
                        Log::error('Failed to preserve task success state: '.$inner->getMessage(), ['task_id' => $task->id]);
                    }
                }

                return true;
            }

            $run->update([
                'status' => 'failed',
                'finished_at' => $finishedAt->toDateTimeString(),
                'duration_ms' => isset($startedAt) ? $startedAt->diffInMilliseconds($finishedAt) : null,
                'error_summary' => substr($e->getMessage(), 0, 255),
                'error_details' => $e->getMessage()."\n".$e->getTraceAsString(),
            ]);

            if (isset($task) && $task) {
                $task->update([
                    'last_run_at' => $finishedAt->toDateTimeString(),
                    'last_status' => 'failed',
                    'last_failure_at' => $finishedAt->toDateTimeString(),
                    'last_error_summary' => substr($e->getMessage(), 0, 255),
                ]);
            }

            // Cache is used for phase 3A consecutive failures.
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
