<?php

namespace App\Services\Znuny;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\ZnunyTicketCreationAttempt;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class ScheduledZnunyTicketCreationAttemptReconciliationService
{
    public function __construct(
        private readonly ScheduledZnunyTicketMarkerRefreshLookupService $markerLookup
    ) {}

    public function reconcile(string|int $attemptId): array
    {
        $attempt = ZnunyTicketCreationAttempt::find($attemptId);

        if (! $attempt) {
            return $this->buildResult(
                resolved: false,
                transitioned: false,
                attemptId: null,
                ticketId: null,
                ticketNumber: null,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                reason: 'Scheduled ticket creation attempt was not found.',
                refreshAttempted: false,
                refreshSucceeded: false,
                refreshExitCode: null
            );
        }

        if ($attempt->source_type !== 'scheduled_run') {
            return $this->buildResult(
                resolved: false,
                transitioned: false,
                attemptId: $attempt->id,
                ticketId: $attempt->ticket_id,
                ticketNumber: $attempt->ticket_number,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                reason: 'Automatic reconciliation is only available for scheduled ticket attempts.',
                refreshAttempted: false,
                refreshSucceeded: false,
                refreshExitCode: null
            );
        }

        if (in_array($attempt->status, [
            ZnunyTicketCreationAttemptStatus::Success,
            ZnunyTicketCreationAttemptStatus::Recovered,
            ZnunyTicketCreationAttemptStatus::ManuallyLinked,
        ], true)) {
            $ticketId = $attempt->ticket_id;
            $ticketNumber = isset($attempt->ticket_number) ? trim((string) $attempt->ticket_number) : '';

            $isValid = $ticketId !== null && is_numeric($ticketId) && $ticketId > 0 && $ticketNumber !== '';

            if ($isValid) {
                return $this->buildResult(
                    resolved: true,
                    transitioned: false,
                    attemptId: $attempt->id,
                    ticketId: $ticketId,
                    ticketNumber: $ticketNumber,
                    lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                    reason: null,
                    refreshAttempted: false,
                    refreshSucceeded: false,
                    refreshExitCode: null
                );
            }

            return $this->buildResult(
                resolved: false,
                transitioned: false,
                attemptId: $attempt->id,
                ticketId: $ticketId,
                ticketNumber: $ticketNumber,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                reason: 'Confirmed ticket attempt has invalid identifiers.',
                refreshAttempted: false,
                refreshSucceeded: false,
                refreshExitCode: null
            );
        }

        if ($attempt->status !== ZnunyTicketCreationAttemptStatus::Uncertain) {
            return $this->buildResult(
                resolved: false,
                transitioned: false,
                attemptId: $attempt->id,
                ticketId: $attempt->ticket_id,
                ticketNumber: $attempt->ticket_number,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                reason: 'Ticket creation attempt is not eligible for automatic reconciliation.',
                refreshAttempted: false,
                refreshSucceeded: false,
                refreshExitCode: null
            );
        }

        $marker = trim((string) $attempt->marker);

        if ($marker === '') {
            return $this->buildResult(
                resolved: false,
                transitioned: false,
                attemptId: $attempt->id,
                ticketId: $attempt->ticket_id,
                ticketNumber: $attempt->ticket_number,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                reason: 'Scheduled ticket creation attempt has no marker.',
                refreshAttempted: false,
                refreshSucceeded: false,
                refreshExitCode: null
            );
        }

        $lookupResult = $this->markerLookup->findExactMarkerWithRefresh($marker);

        $lookupStatus = $lookupResult['status'];
        $refreshAttempted = $lookupResult['refresh_attempted'] ?? false;
        $refreshSucceeded = $lookupResult['refresh_succeeded'] ?? false;
        $refreshExitCode = $lookupResult['refresh_exit_code'] ?? null;

        if ($lookupStatus === ScheduledZnunyTicketMarkerLookupStatus::Found) {
            return DB::transaction(function () use ($attempt, $lookupResult, $refreshAttempted, $refreshSucceeded, $refreshExitCode) {
                $lockedAttempt = ZnunyTicketCreationAttempt::lockForUpdate()->find($attempt->id);

                if (! $lockedAttempt) {
                    return $this->buildResult(
                        resolved: false,
                        transitioned: false,
                        attemptId: $attempt->id,
                        ticketId: $attempt->ticket_id,
                        ticketNumber: $attempt->ticket_number,
                        lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Found,
                        reason: 'Attempt was deleted concurrently.',
                        refreshAttempted: $refreshAttempted,
                        refreshSucceeded: $refreshSucceeded,
                        refreshExitCode: $refreshExitCode
                    );
                }

                $lockedAttempt->last_checked_at = Carbon::now();
                $lockedAttempt->check_attempts = ((int) $lockedAttempt->check_attempts) + 1;

                if (in_array($lockedAttempt->status, [
                    ZnunyTicketCreationAttemptStatus::Success,
                    ZnunyTicketCreationAttemptStatus::Recovered,
                    ZnunyTicketCreationAttemptStatus::ManuallyLinked,
                ], true)) {
                    $lockedId = $lockedAttempt->ticket_id;
                    $lockedNumber = isset($lockedAttempt->ticket_number) ? trim((string) $lockedAttempt->ticket_number) : '';
                    $isValid = $lockedId !== null && is_numeric($lockedId) && $lockedId > 0 && $lockedNumber !== '';

                    $lockedAttempt->save();

                    if ($isValid) {
                        return $this->buildResult(
                            resolved: true,
                            transitioned: false,
                            attemptId: $lockedAttempt->id,
                            ticketId: $lockedId,
                            ticketNumber: $lockedNumber,
                            lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Found,
                            reason: null,
                            refreshAttempted: $refreshAttempted,
                            refreshSucceeded: $refreshSucceeded,
                            refreshExitCode: $refreshExitCode
                        );
                    } else {
                        return $this->buildResult(
                            resolved: false,
                            transitioned: false,
                            attemptId: $lockedAttempt->id,
                            ticketId: $lockedAttempt->ticket_id,
                            ticketNumber: $lockedAttempt->ticket_number,
                            lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Found,
                            reason: 'Concurrently confirmed ticket attempt has invalid identifiers.',
                            refreshAttempted: $refreshAttempted,
                            refreshSucceeded: $refreshSucceeded,
                            refreshExitCode: $refreshExitCode
                        );
                    }
                }

                if ($lockedAttempt->status !== ZnunyTicketCreationAttemptStatus::Uncertain) {
                    $lockedAttempt->save();
                    return $this->buildResult(
                        resolved: false,
                        transitioned: false,
                        attemptId: $lockedAttempt->id,
                        ticketId: $lockedAttempt->ticket_id,
                        ticketNumber: $lockedAttempt->ticket_number,
                        lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Found,
                        reason: 'Attempt status changed concurrently and is no longer eligible.',
                        refreshAttempted: $refreshAttempted,
                        refreshSucceeded: $refreshSucceeded,
                        refreshExitCode: $refreshExitCode
                    );
                }

                $lockedAttempt->status = ZnunyTicketCreationAttemptStatus::Recovered;
                $lockedAttempt->ticket_id = $lookupResult['ticket_id'];
                $lockedAttempt->ticket_number = trim((string) $lookupResult['ticket_number']);

                if ($lockedAttempt->finished_at === null) {
                    $lockedAttempt->finished_at = Carbon::now();
                }

                $lockedAttempt->error_summary = null;
                $lockedAttempt->error_details = null;
                $lockedAttempt->save();

                return $this->buildResult(
                    resolved: true,
                    transitioned: true,
                    attemptId: $lockedAttempt->id,
                    ticketId: $lockedAttempt->ticket_id,
                    ticketNumber: $lockedAttempt->ticket_number,
                    lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::Found,
                    reason: null,
                    refreshAttempted: $refreshAttempted,
                    refreshSucceeded: $refreshSucceeded,
                    refreshExitCode: $refreshExitCode
                );
            });
        }

        DB::transaction(function () use ($attempt) {
            $lockedAttempt = ZnunyTicketCreationAttempt::lockForUpdate()->find($attempt->id);
            if ($lockedAttempt) {
                $lockedAttempt->last_checked_at = Carbon::now();
                $lockedAttempt->check_attempts = ((int) $lockedAttempt->check_attempts) + 1;
                $lockedAttempt->save();
            }
        });

        if ($lookupStatus === ScheduledZnunyTicketMarkerLookupStatus::NotFound) {
            return $this->buildResult(
                resolved: false,
                transitioned: false,
                attemptId: $attempt->id,
                ticketId: $attempt->ticket_id,
                ticketNumber: $attempt->ticket_number,
                lookupStatus: ScheduledZnunyTicketMarkerLookupStatus::NotFound,
                reason: 'No open Znuny ticket was found for the scheduled marker after refresh.',
                refreshAttempted: $refreshAttempted,
                refreshSucceeded: $refreshSucceeded,
                refreshExitCode: $refreshExitCode
            );
        }

        return $this->buildResult(
            resolved: false,
            transitioned: false,
            attemptId: $attempt->id,
            ticketId: $attempt->ticket_id,
            ticketNumber: $attempt->ticket_number,
            lookupStatus: $lookupStatus,
            reason: $lookupResult['reason'] ?? null,
            refreshAttempted: $refreshAttempted,
            refreshSucceeded: $refreshSucceeded,
            refreshExitCode: $refreshExitCode
        );
    }

    private function buildResult(
        bool $resolved,
        bool $transitioned,
        int|string|null $attemptId,
        int|string|null $ticketId,
        string|null $ticketNumber,
        ScheduledZnunyTicketMarkerLookupStatus $lookupStatus,
        ?string $reason,
        bool $refreshAttempted,
        bool $refreshSucceeded,
        ?int $refreshExitCode
    ): array {
        return [
            'resolved' => $resolved,
            'transitioned' => $transitioned,
            'attempt_id' => $attemptId,
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber,
            'lookup_status' => $lookupStatus,
            'reason' => $reason,
            'refresh_attempted' => $refreshAttempted,
            'refresh_succeeded' => $refreshSucceeded,
            'refresh_exit_code' => $refreshExitCode,
        ];
    }
}
