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
                reason: 'The selected Znuny TicketID is invalid.'
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
                reason: 'The selected Znuny TicketNumber is invalid.'
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
                reason: 'The selected Znuny ticket is not present in the current marker lookup result.'
            );
        }

        $transitioned = false;
        $finalStatus = null;
        $conflictReason = null;

        DB::transaction(function () use (
                $attemptId,
                $normalizedTicketId,
                $trimmedTicketNumber,
                $review,
                &$transitioned,
                &$finalStatus,
                &$conflictReason
            ) {
                $lockedAttempt = ZnunyTicketCreationAttempt::lockForUpdate()->find($attemptId);
                if (! $lockedAttempt) {
                    $conflictReason = 'The Scheduled Znuny ticket creation attempt changed during manual linking.';
                    return;
                }

                if ($lockedAttempt->status === ZnunyTicketCreationAttemptStatus::ManuallyLinked) {
                    $existingId = filter_var($lockedAttempt->ticket_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $existingNumber = trim((string) $lockedAttempt->ticket_number);

                    if ($existingId === $normalizedTicketId && $existingNumber === $trimmedTicketNumber) {
                        $transitioned = false;
                        $finalStatus = $lockedAttempt->status->value;
                        return;
                    }

                    $conflictReason = 'The attempt has already been manually linked to a different Znuny ticket.';
                    return;
                }

                if ($lockedAttempt->status !== ZnunyTicketCreationAttemptStatus::Uncertain) {
                    $conflictReason = 'The Scheduled Znuny ticket creation attempt changed during manual linking.';
                    return;
                }

                if ($lockedAttempt->source_type !== 'scheduled_run') {
                    $conflictReason = 'The Scheduled Znuny ticket creation attempt changed during manual linking.';
                    return;
                }

                if ((string) $lockedAttempt->source_id !== (string) $review['run_id']) {
                    $conflictReason = 'The Scheduled Znuny ticket creation attempt changed during manual linking.';
                    return;
                }

                $lockedMarker = (string) $lockedAttempt->marker;
                $reviewMarker = (string) ($review['marker'] ?? '');

                if (
                    trim($lockedMarker) === ''
                    || trim($reviewMarker) === ''
                    || $lockedMarker !== $reviewMarker
                ) {
                    $conflictReason = 'The Scheduled Znuny ticket creation attempt changed during manual linking.';
                    return;
                }

                $lockedRun = ScheduledZnunyTaskRun::lockForUpdate()->find($review['run_id']);
                if (! $lockedRun) {
                    $conflictReason = 'The Scheduled Znuny task run linked to this attempt was not found.';
                    return;
                }

                if ((string) $lockedRun->scheduled_znuny_task_id !== (string) $review['task_id']) {
                    $conflictReason = 'The Scheduled Znuny ticket creation attempt changed during manual linking.';
                    return;
                }

                $lockedTask = ScheduledZnunyTask::lockForUpdate()->find($review['task_id']);
                if (! $lockedTask) {
                    $conflictReason = 'The Scheduled Znuny task linked to this attempt was not found.';
                    return;
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
                $lockedRun->save();

                $lockedTask->last_ticket_id = $normalizedTicketId;
                $lockedTask->last_ticket_number = $trimmedTicketNumber;
                $lockedTask->save();

                $transitioned = true;
                $finalStatus = $lockedAttempt->status->value;
            });

        if ($conflictReason !== null) {
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
                reason: $conflictReason
            );
        }

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
        string|null $attemptStatus,
        int|string|null $runId,
        int|string|null $taskId,
        int|string|null $ticketId,
        string|null $ticketNumber,
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
