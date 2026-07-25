<?php

declare(strict_types=1);

namespace App\Services\Znuny;

use App\Enums\ScheduledZnunyTicketCreationDispatchDecision;
use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\ZnunyTicketCreationAttempt;

class ScheduledZnunyTicketCreationDuplicateGuard
{
    /**
     * @return array{
     *     decision: ScheduledZnunyTicketCreationDispatchDecision,
     *     attempt: ?ZnunyTicketCreationAttempt,
     *     ticket_id: int|string|null,
     *     ticket_number: string|null,
     *     reason: string
     * }
     */
    public function determineDispatchDecision(string|int $runId): array
    {
        $attempt = ZnunyTicketCreationAttempt::where('source_type', 'scheduled_run')
            ->where('source_id', (string) $runId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if (! $attempt) {
            return $this->result(ScheduledZnunyTicketCreationDispatchDecision::Proceed, null, 'No previous attempt exists for this run.');
        }

        switch ($attempt->status) {
            case ZnunyTicketCreationAttemptStatus::Preparing:
            case ZnunyTicketCreationAttemptStatus::ConfirmedFailed:
            case ZnunyTicketCreationAttemptStatus::ResolvedWithoutTicket:
                return $this->result(ScheduledZnunyTicketCreationDispatchDecision::Proceed, $attempt, 'Previous attempt did not result in a ticket.');

            case ZnunyTicketCreationAttemptStatus::Sending:
            case ZnunyTicketCreationAttemptStatus::Uncertain:
                return $this->result(ScheduledZnunyTicketCreationDispatchDecision::BlockUncertain, $attempt, 'A previous Scheduled ticket creation attempt may already have reached Znuny. Automatic duplicate creation was blocked.');

            case ZnunyTicketCreationAttemptStatus::Success:
            case ZnunyTicketCreationAttemptStatus::Recovered:
            case ZnunyTicketCreationAttemptStatus::ManuallyLinked:
            case ZnunyTicketCreationAttemptStatus::Orphaned:
                if ($this->hasValidIdentifiers($attempt)) {
                    return $this->result(ScheduledZnunyTicketCreationDispatchDecision::ReuseConfirmed, $attempt, 'A confirmed ticket already exists for this run.');
                }
                return $this->result(ScheduledZnunyTicketCreationDispatchDecision::BlockUncertain, $attempt, 'A previous Scheduled ticket creation attempt may already have reached Znuny. Automatic duplicate creation was blocked.');
        }

        return $this->result(ScheduledZnunyTicketCreationDispatchDecision::BlockUncertain, $attempt, 'A previous Scheduled ticket creation attempt may already have reached Znuny. Automatic duplicate creation was blocked.');
    }

    private function hasValidIdentifiers(ZnunyTicketCreationAttempt $attempt): bool
    {
        $ticketId = $attempt->ticket_id;
        if ($ticketId === null) {
            return false;
        }
        if (! is_numeric($ticketId)) {
            return false;
        }
        if ($ticketId <= 0) {
            return false;
        }

        $ticketNumber = $attempt->ticket_number;
        if ($ticketNumber === null || trim((string) $ticketNumber) === '') {
            return false;
        }

        return true;
    }

    /**
     * @return array{
     *     decision: ScheduledZnunyTicketCreationDispatchDecision,
     *     attempt: ?ZnunyTicketCreationAttempt,
     *     ticket_id: int|string|null,
     *     ticket_number: string|null,
     *     reason: string
     * }
     */
    private function result(ScheduledZnunyTicketCreationDispatchDecision $decision, ?ZnunyTicketCreationAttempt $attempt, string $reason): array
    {
        return [
            'decision' => $decision,
            'attempt' => $attempt,
            'ticket_id' => $attempt?->ticket_id,
            'ticket_number' => $attempt?->ticket_number,
            'reason' => $reason,
        ];
    }
}
