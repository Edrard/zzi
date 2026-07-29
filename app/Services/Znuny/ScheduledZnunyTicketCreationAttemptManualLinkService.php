<?php

namespace App\Services\Znuny;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ScheduledZnunyTicketCreationAttemptManualLinkService
{
    public function __construct(
        private readonly ScheduledZnunyTicketCreationAttemptManualReviewService $manualReview,
        private readonly AuditLogger $auditLogger
    ) {}

    public function link(
        string|int $attemptId,
        string|int $ticketId,
        string $ticketNumber,
    ): array {
        $normalizedTicketId = filter_var($ticketId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalizedTicketId === false) {
            return $this->buildResult(
                linked: false,
                transitioned: false,
                attemptId: $attemptId,
                attemptStatus: null,
                runId: null,
                taskId: null,
                ticketId: null,
                ticketNumber: null,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                reason: __('scheduled_znuny_task_runs.review.actions.manual_link.errors.invalid_id')
            );
        }

        $trimmedTicketNumber = trim($ticketNumber);
        if ($trimmedTicketNumber === '') {
            return $this->buildResult(
                linked: false,
                transitioned: false,
                attemptId: $attemptId,
                attemptStatus: null,
                runId: null,
                taskId: null,
                ticketId: null,
                ticketNumber: null,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                reason: __('scheduled_znuny_task_runs.review.actions.manual_link.errors.invalid_number')
            );
        }

        $normalizedAttemptId = filter_var($attemptId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalizedAttemptId === false) {
            return $this->buildResult(
                linked: false,
                transitioned: false,
                attemptId: $attemptId,
                attemptStatus: null,
                runId: null,
                taskId: null,
                ticketId: null,
                ticketNumber: null,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                reason: __('scheduled_znuny_task_runs.review.actions.manual_link.errors.not_found')
            );
        }

        $attempt = ZnunyTicketCreationAttempt::find($normalizedAttemptId);
        if (! $attempt) {
            return $this->buildResult(
                linked: false,
                transitioned: false,
                attemptId: $normalizedAttemptId,
                attemptStatus: null,
                runId: null,
                taskId: null,
                ticketId: null,
                ticketNumber: null,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                reason: __('scheduled_znuny_task_runs.review.actions.manual_link.errors.not_found')
            );
        }

        if ($attempt->source_type !== 'scheduled_run') {
            return $this->buildResult(
                linked: false,
                transitioned: false,
                attemptId: $normalizedAttemptId,
                attemptStatus: $attempt->status->value ?? null,
                runId: $attempt->source_id,
                taskId: null,
                ticketId: null,
                ticketNumber: null,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                reason: __('scheduled_znuny_task_runs.review.actions.manual_link.errors.not_scheduled')
            );
        }

        $run = ScheduledZnunyTaskRun::find($attempt->source_id);
        $taskId = $run ? $run->scheduled_znuny_task_id : null;

        if ($attempt->status === ZnunyTicketCreationAttemptStatus::ManuallyLinked) {
            $existingId = filter_var($attempt->ticket_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $existingNumber = trim((string) $attempt->ticket_number);

            if ($existingId === $normalizedTicketId && $existingNumber === $trimmedTicketNumber) {
                return $this->buildResult(
                    linked: true,
                    transitioned: false,
                    attemptId: $normalizedAttemptId,
                    attemptStatus: $attempt->status->value,
                    runId: $attempt->source_id,
                    taskId: $taskId,
                    ticketId: $normalizedTicketId,
                    ticketNumber: $trimmedTicketNumber,
                    lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                    reason: null
                );
            }

            return $this->buildResult(
                linked: false,
                transitioned: false,
                attemptId: $normalizedAttemptId,
                attemptStatus: $attempt->status->value,
                runId: $attempt->source_id,
                taskId: $taskId,
                ticketId: null,
                ticketNumber: null,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                reason: __('scheduled_znuny_task_runs.review.actions.manual_link.errors.already_linked_different')
            );
        }

        if (in_array($attempt->status, [
            ZnunyTicketCreationAttemptStatus::Success,
            ZnunyTicketCreationAttemptStatus::Recovered,
            ZnunyTicketCreationAttemptStatus::ResolvedWithoutTicket,
            ZnunyTicketCreationAttemptStatus::ConfirmedFailed,
        ], true)) {
            return $this->buildResult(
                linked: false,
                transitioned: false,
                attemptId: $normalizedAttemptId,
                attemptStatus: $attempt->status->value,
                runId: $attempt->source_id,
                taskId: $taskId,
                ticketId: null,
                ticketNumber: null,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                reason: __('scheduled_znuny_task_runs.review.actions.manual_link.errors.terminal_state')
            );
        }

        if ($attempt->status !== ZnunyTicketCreationAttemptStatus::Uncertain) {
            return $this->buildResult(
                linked: false,
                transitioned: false,
                attemptId: $normalizedAttemptId,
                attemptStatus: $attempt->status->value ?? null,
                runId: $attempt->source_id,
                taskId: $taskId,
                ticketId: null,
                ticketNumber: null,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                reason: __('scheduled_znuny_task_runs.review.actions.manual_link.errors.not_uncertain')
            );
        }

        $review = $this->manualReview->recheck($attemptId);

        $lookupStatus = $review['lookup_status'] ?? ScheduledZnunyTicketMarkerLookupStatus::Unavailable;

        $isValidReview = ($review['found'] ?? false) === true
            && ($review['eligible'] ?? false) === true
            && ($review['resolved'] ?? true) === false
            && in_array($lookupStatus, [
                ScheduledZnunyTicketMarkerLookupStatus::Found,
                ScheduledZnunyTicketMarkerLookupStatus::Multiple,
            ], true);

        if (! $isValidReview) {
            return $this->buildResult(
                linked: false,
                transitioned: false,
                attemptId: $review['attempt_id'] ?? null,
                attemptStatus: $review['attempt_status'] ?? null,
                runId: $review['run_id'] ?? null,
                taskId: $review['task_id'] ?? null,
                ticketId: null,
                ticketNumber: null,
                lookupStatus: $lookupStatus,
                reason: $review['reason'] ?? null
            );
        }

        $matchFound = false;
        foreach ($review['matches'] ?? [] as $match) {
            $matchId = filter_var($match['ticket_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $matchNumber = trim((string) ($match['ticket_number'] ?? ''));

            if ($matchId !== false && $matchId === $normalizedTicketId && $matchNumber === $trimmedTicketNumber) {
                $matchFound = true;
                break;
            }
        }

        if (! $matchFound) {
            return $this->buildResult(
                linked: false,
                transitioned: false,
                attemptId: $review['attempt_id'],
                attemptStatus: $review['attempt_status'],
                runId: $review['run_id'],
                taskId: $review['task_id'],
                ticketId: null,
                ticketNumber: null,
                lookupStatus: $lookupStatus,
                reason: __('scheduled_znuny_task_runs.review.actions.manual_link.errors.not_in_lookup')
            );
        }

        $transactionResult = null;

        try {
            $transactionResult = DB::transaction(function () use (
                $attemptId,
                $normalizedTicketId,
                $trimmedTicketNumber,
                $review
            ) {
                $lockedRun = ScheduledZnunyTaskRun::lockForUpdate()->find($review['run_id']);
                if (! $lockedRun) {
                    return ['conflictReason' => __('scheduled_znuny_task_runs.review.actions.manual_link.errors.run_not_found')];
                }

                $lockedTask = ScheduledZnunyTask::lockForUpdate()->find($review['task_id']);
                if (! $lockedTask) {
                    return ['conflictReason' => __('scheduled_znuny_task_runs.review.actions.manual_link.errors.task_not_found')];
                }

                $lockedAttempt = ZnunyTicketCreationAttempt::lockForUpdate()->find($attemptId);
                if (! $lockedAttempt) {
                    return ['conflictReason' => __('scheduled_znuny_task_runs.review.actions.manual_link.errors.attempt_changed')];
                }

                if ((string) $lockedRun->scheduled_znuny_task_id !== (string) $review['task_id']) {
                    return ['conflictReason' => __('scheduled_znuny_task_runs.review.actions.manual_link.errors.attempt_changed')];
                }

                if ($lockedAttempt->status === ZnunyTicketCreationAttemptStatus::ManuallyLinked) {
                    $existingId = filter_var($lockedAttempt->ticket_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $existingNumber = trim((string) $lockedAttempt->ticket_number);

                    if ($existingId === $normalizedTicketId && $existingNumber === $trimmedTicketNumber) {
                        return [
                            'transitioned' => false,
                            'finalStatus' => $lockedAttempt->status->value,
                        ];
                    }

                    return ['conflictReason' => __('scheduled_znuny_task_runs.review.actions.manual_link.errors.already_linked_different')];
                }

                if ($lockedAttempt->status !== ZnunyTicketCreationAttemptStatus::Uncertain) {
                    return ['conflictReason' => __('scheduled_znuny_task_runs.review.actions.manual_link.errors.attempt_changed')];
                }

                if ($lockedAttempt->source_type !== 'scheduled_run') {
                    return ['conflictReason' => __('scheduled_znuny_task_runs.review.actions.manual_link.errors.attempt_changed')];
                }

                if ((string) $lockedAttempt->source_id !== (string) $review['run_id']) {
                    return ['conflictReason' => __('scheduled_znuny_task_runs.review.actions.manual_link.errors.attempt_changed')];
                }

                $lockedMarker = (string) $lockedAttempt->marker;
                $reviewMarker = (string) ($review['marker'] ?? '');

                if (
                    trim($lockedMarker) === ''
                    || trim($reviewMarker) === ''
                    || $lockedMarker !== $reviewMarker
                ) {
                    return ['conflictReason' => __('scheduled_znuny_task_runs.review.actions.manual_link.errors.attempt_changed')];
                }

                if ($lockedRun->resolved_at !== null) {
                    return ['conflictReason' => __('scheduled_znuny_task_runs.review.actions.manual_link.errors.attempt_changed')];
                }

                $lockedAttempt->status = ZnunyTicketCreationAttemptStatus::ManuallyLinked;
                $lockedAttempt->ticket_id = $normalizedTicketId;
                $lockedAttempt->ticket_number = $trimmedTicketNumber;

                if ($lockedAttempt->finished_at === null) {
                    $lockedAttempt->finished_at = Carbon::now('UTC');
                }

                $lockedAttempt->save();

                $lockedRun->ticket_id = $normalizedTicketId;
                $lockedRun->ticket_number = $trimmedTicketNumber;
                $lockedRun->resolved_at = Carbon::now('UTC');
                $lockedRun->resolution_type = 'manual_link';
                $lockedRun->save();

                $lockedTask->last_status = 'success';
                $lockedTask->last_error_summary = null;
                $lockedTask->last_ticket_id = $normalizedTicketId;
                $lockedTask->last_ticket_number = $trimmedTicketNumber;
                $lockedTask->save();

                return [
                    'transitioned' => true,
                    'finalStatus' => $lockedAttempt->status->value,
                ];
            });
        } catch (Throwable) {
            Log::error('Transaction error during scheduled Znuny manual link.', [
                'attempt_id' => $attemptId,
            ]);
            $transactionResult = ['conflictReason' => __('scheduled_znuny_task_runs.review.actions.manual_link.errors.transaction_error')];
        }

        if (isset($transactionResult['conflictReason'])) {
            return $this->buildResult(
                linked: false,
                transitioned: false,
                attemptId: $review['attempt_id'] ?? $attemptId,
                attemptStatus: $review['attempt_status'] ?? null,
                runId: $review['run_id'] ?? null,
                taskId: $review['task_id'] ?? null,
                ticketId: null,
                ticketNumber: null,
                lookupStatus: $lookupStatus,
                reason: $transactionResult['conflictReason']
            );
        }

        $transitioned = $transactionResult['transitioned'] ?? false;
        $finalStatus = $transactionResult['finalStatus'] ?? null;

        if ($transitioned) {
            try {
                $this->auditLogger->log(
                    action: 'scheduled_znuny_attempt_manually_linked',
                    entityType: 'ZnunyTicketCreationAttempt',
                    entityId: (string) $review['attempt_id'],
                    context: [
                        'run_id' => $review['run_id'],
                        'task_id' => $review['task_id'],
                        'task_name' => $review['task_name'] ?? null,
                        'ticket_id' => $normalizedTicketId,
                        'ticket_number' => $trimmedTicketNumber,
                        'marker' => $review['marker'] ?? null,
                        'previous_status' => ZnunyTicketCreationAttemptStatus::Uncertain->value,
                        'new_status' => ZnunyTicketCreationAttemptStatus::ManuallyLinked->value,
                        'resolution_type' => 'manual_link',
                    ]
                );
            } catch (Throwable) {
                Log::error('Audit log creation failed after manual linking.', [
                    'side-effect type' => 'audit_log',
                    'attempt ID' => $review['attempt_id'],
                ]);
            }
        }

        return $this->buildResult(
            linked: true,
            transitioned: $transitioned,
            attemptId: $review['attempt_id'],
            attemptStatus: $finalStatus,
            runId: $review['run_id'],
            taskId: $review['task_id'],
            ticketId: $normalizedTicketId,
            ticketNumber: $trimmedTicketNumber,
            lookupStatus: $lookupStatus,
            reason: null
        );
    }

    private function buildResult(
        bool $linked,
        bool $transitioned,
        int|string|null $attemptId,
        ?string $attemptStatus,
        int|string|null $runId,
        int|string|null $taskId,
        int|string|null $ticketId,
        ?string $ticketNumber,
        ScheduledZnunyTicketMarkerLookupStatus $lookupStatus,
        ?string $reason
    ): array {
        return [
            'linked' => $linked,
            'transitioned' => $transitioned,
            'attempt_id' => $attemptId,
            'attempt_status' => $attemptStatus,
            'run_id' => $runId,
            'task_id' => $taskId,
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber,
            'lookup_status' => $lookupStatus,
            'reason' => $reason,
        ];
    }
}
