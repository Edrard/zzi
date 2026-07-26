<?php

namespace App\Services\Znuny;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\User;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ScheduledZnunyTicketCreationAttemptManualRetryService
{
    public function __construct(
        private readonly ScheduledZnunyTicketCreationAttemptManualReviewService $manualReview,
        private readonly AuditLogger $auditLogger
    ) {}

    public function retry(string|int $attemptId, ?User $actor = null): array
    {
        $normalizedAttemptId = filter_var($attemptId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalizedAttemptId === false) {
            return $this->buildResult(
                created: false,
                existing: false,
                attemptId: $attemptId,
                originalRunId: null,
                retryRunId: null,
                taskId: null,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                reason: 'The selected Znuny ticket creation attempt ID is invalid.'
            );
        }

        $review = $this->manualReview->recheck($normalizedAttemptId);

        $lookupStatus = $review['lookup_status'] ?? ScheduledZnunyTicketMarkerLookupStatus::Unavailable;

        $isValidReview = ($review['found'] ?? false) === true
            && ($review['eligible'] ?? false) === true
            && ($review['resolved'] ?? true) === false
            && ($review['attempt_status'] ?? null) === ZnunyTicketCreationAttemptStatus::Uncertain->value
            && $lookupStatus === ScheduledZnunyTicketMarkerLookupStatus::NotFound;

        if (! $isValidReview) {
            return $this->buildResult(
                created: false,
                existing: false,
                attemptId: $review['attempt_id'] ?? null,
                originalRunId: $review['run_id'] ?? null,
                retryRunId: null,
                taskId: $review['task_id'] ?? null,
                lookupStatus: $lookupStatus,
                reason: $review['reason'] ?? 'The attempt is not eligible for a manual retry.'
            );
        }

        $conflictReason = null;
        $retryRunId = null;
        $created = false;
        $existing = false;

        $originalRunId = $review['run_id'] ?? null;
        $taskId = $review['task_id'] ?? null;

        try {
            DB::transaction(function () use (
                $normalizedAttemptId,
                $review,
                $actor,
                &$conflictReason,
                &$retryRunId,
                &$created,
                &$existing
            ) {
                $lockedAttempt = ZnunyTicketCreationAttempt::lockForUpdate()->find($normalizedAttemptId);
                if (! $lockedAttempt) {
                    $conflictReason = 'The Scheduled Znuny ticket creation attempt changed during manual retry.';
                    return;
                }

                if ($lockedAttempt->status !== ZnunyTicketCreationAttemptStatus::Uncertain) {
                    $conflictReason = 'The Scheduled Znuny ticket creation attempt changed during manual retry.';
                    return;
                }

                if ($lockedAttempt->source_type !== 'scheduled_run') {
                    $conflictReason = 'The Scheduled Znuny ticket creation attempt changed during manual retry.';
                    return;
                }

                if ((string) $lockedAttempt->source_id !== (string) $review['run_id']) {
                    $conflictReason = 'The Scheduled Znuny ticket creation attempt changed during manual retry.';
                    return;
                }

                $lockedMarker = (string) $lockedAttempt->marker;
                $reviewMarker = (string) ($review['marker'] ?? '');

                if (trim($lockedMarker) === '' || $lockedMarker !== $reviewMarker) {
                    $conflictReason = 'The Scheduled Znuny ticket creation attempt changed during manual retry.';
                    return;
                }

                $lockedRun = ScheduledZnunyTaskRun::lockForUpdate()->find($review['run_id']);
                if (! $lockedRun) {
                    $conflictReason = 'The Scheduled Znuny task run linked to this attempt was not found.';
                    return;
                }

                if ((string) $lockedRun->scheduled_znuny_task_id !== (string) $review['task_id']) {
                    $conflictReason = 'The Scheduled Znuny ticket creation attempt changed during manual retry.';
                    return;
                }

                $lockedTask = ScheduledZnunyTask::lockForUpdate()->find($review['task_id']);
                if (! $lockedTask) {
                    $conflictReason = 'The Scheduled Znuny task linked to this attempt was not found.';
                    return;
                }

                $existingRetry = ScheduledZnunyTaskRun::where('manual_retry_of_attempt_id', $normalizedAttemptId)->first();
                if ($existingRetry) {
                    $retryRunId = $existingRetry->id;
                    $existing = true;
                    return;
                }

                $scheduledFor = Carbon::now('UTC')->startOfSecond();
                while (ScheduledZnunyTaskRun::where('scheduled_znuny_task_id', $lockedTask->id)
                    ->where('scheduled_for', $scheduledFor->toDateTimeString())
                    ->exists()) {
                    $scheduledFor = $scheduledFor->addSecond();
                }

                $newRun = ScheduledZnunyTaskRun::create([
                    'scheduled_znuny_task_id' => $lockedRun->scheduled_znuny_task_id,
                    'task_name_snapshot' => $lockedRun->task_name_snapshot,
                    'run_type' => 'manual_retry',
                    'status' => 'pending',
                    'scheduled_for' => $scheduledFor->toDateTimeString(),
                    'created_by' => $actor?->getKey() ?? auth()->id(),
                    'manual_retry_of_attempt_id' => $lockedAttempt->id,
                ]);

                $retryRunId = $newRun->id;
                $created = true;
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateManualRetryException($e)) {
                $existingRetry = ScheduledZnunyTaskRun::where('manual_retry_of_attempt_id', $normalizedAttemptId)->first();
                if ($existingRetry) {
                    return $this->buildResult(
                        created: false,
                        existing: true,
                        attemptId: $review['attempt_id'],
                        originalRunId: $originalRunId,
                        retryRunId: $existingRetry->id,
                        taskId: $taskId,
                        lookupStatus: $lookupStatus,
                        reason: null
                    );
                }
            }
            throw $e;
        }

        if ($conflictReason !== null) {
            return $this->buildResult(
                created: false,
                existing: false,
                attemptId: $review['attempt_id'] ?? $normalizedAttemptId,
                originalRunId: $originalRunId,
                retryRunId: null,
                taskId: $taskId,
                lookupStatus: $lookupStatus,
                reason: $conflictReason
            );
        }

        if ($created) {
            try {
                $this->auditLogger->log(
                    action: 'scheduled_znuny_attempt_manual_retry_created',
                    entityType: 'ZnunyTicketCreationAttempt',
                    entityId: (string) $normalizedAttemptId,
                    context: [
                        'original_run_id' => $originalRunId,
                        'retry_run_id' => $retryRunId,
                        'task_id' => $taskId,
                        'task_name' => $review['task_name'] ?? null,
                        'marker' => $review['marker'] ?? null,
                        'run_type' => 'manual_retry',
                        'run_status' => 'pending',
                    ],
                    user: $actor
                );
            } catch (Throwable $e) {
                Log::error('Audit log creation failed after manual retry creation.', [
                    'side-effect type' => 'audit_log',
                    'attempt ID' => $normalizedAttemptId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->buildResult(
            created: $created,
            existing: $existing,
            attemptId: $review['attempt_id'],
            originalRunId: $originalRunId,
            retryRunId: $retryRunId,
            taskId: $taskId,
            lookupStatus: $lookupStatus,
            reason: null
        );
    }

    private function isDuplicateManualRetryException(QueryException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        if ($driverCode !== 1062) {
            return false;
        }

        $errorContext = ($e->errorInfo[2] ?? '') . ' ' . $e->getMessage();
        return str_contains($errorContext, 'szt_runs_manual_retry_attempt_unique');
    }

    private function buildResult(
        bool $created,
        bool $existing,
        int|string|null $attemptId,
        int|string|null $originalRunId,
        int|string|null $retryRunId,
        int|string|null $taskId,
        ScheduledZnunyTicketMarkerLookupStatus $lookupStatus,
        ?string $reason
    ): array {
        return [
            'created' => $created,
            'existing' => $existing,
            'attempt_id' => $attemptId,
            'original_run_id' => $originalRunId,
            'retry_run_id' => $retryRunId,
            'task_id' => $taskId,
            'lookup_status' => $lookupStatus,
            'reason' => $reason,
        ];
    }
}
