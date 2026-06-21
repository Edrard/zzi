<?php

namespace App\Services\Znuny;

use App\Models\ZabbixTicket;

class ZnunyLinkedTicketCloseService
{
    public function __construct(
        private ZnunyClient $znunyClient
    ) {}

    /**
     * Executes the close action via /TicketClose and verifies the state.
     */
    public function closeTicket(ZabbixTicket $ticket, string $subject, string $body, string $reason): array
    {
        $payload = [
            'Kind' => 'internal_note',
            'Subject' => $subject,
            'Body' => $body,
            'Reason' => $reason,
        ];

        // Call Znuny controlled close endpoint
        $response = null;
        $apiError = null;

        try {
            $response = $this->znunyClient->closeTicket($ticket->znuny_ticket_id, $payload);
        } catch (\Throwable $e) {
            $apiError = $e->getMessage();
        }

        if ($apiError && str_starts_with($apiError, 'Znuny API Error:')) {
            return [
                'success' => false,
                'ticket_number' => $ticket->znuny_ticket_number,
                'reason' => 'Failed to close in Znuny: '.$apiError,
            ];
        }

        if ($response && ! $response['success']) {
            return [
                'success' => false,
                'ticket_number' => $ticket->znuny_ticket_number,
                'reason' => 'Failed to close in Znuny: '.implode(', ', $response['errors'] ?? ['Unknown error']),
            ];
        }

        $stateType = $response['state_type'] ?? null;
        $stateName = $response['state'] ?? null;

        // Perform read-after-write verification only if state is missing from close response
        if (! $stateType || ! $stateName) {
            try {
                $ticketSnapshot = $this->znunyClient->getTicket($ticket->znuny_ticket_id);
                $stateType = $ticketSnapshot['StateType'] ?? null;
                $stateName = $ticketSnapshot['State'] ?? null;
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'ticket_number' => $ticket->znuny_ticket_number,
                    'reason' => 'Verification failed: '.$e->getMessage(),
                ];
            }
        }

        $isClosed = $stateType === 'closed' || str_contains(strtolower((string) $stateName), 'closed');

        if (! $isClosed) {
            return [
                'success' => false,
                'ticket_number' => $ticket->znuny_ticket_number,
                'reason' => 'Ticket is still open after close attempt.',
            ];
        }

        // Update local DB safely on verified success
        $ticket->update([
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_CLOSED,
            'manual_lifecycle_last_checked_at' => now(),
            'znuny_state_name' => $stateName,
            'znuny_ticket_state_type' => $stateType,
            'znuny_ticket_changed_at' => $ticketSnapshot['Changed'] ?? now(),
        ]);

        return [
            'success' => true,
            'ticket_number' => $ticket->znuny_ticket_number,
            'reason' => 'Closed successfully.',
        ];
    }
}
