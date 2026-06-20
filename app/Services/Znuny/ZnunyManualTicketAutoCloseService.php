<?php

namespace App\Services\Znuny;

use App\Models\ZabbixTicket;

class ZnunyManualTicketAutoCloseService
{
    public function __construct(
        private ZnunyClient $znunyClient,
        private ZnunyManualTicketCloseCandidateService $candidateService
    ) {}

    /**
     * Executes the close action for a specific ticket if it passes all eligibility checks.
     */
    public function executeClose(ZabbixTicket $ticket): array
    {
        // 1. Re-evaluate eligibility using the dry-run service logic
        $report = $this->candidateService->review($ticket->id);

        if (empty($report['candidates'])) {
            return [
                'success' => false,
                'ticket_number' => $ticket->znuny_ticket_number,
                'skipped' => true,
                'reason' => 'Ticket no longer eligible for auto-close at execution time.',
            ];
        }

        // 2. Prepare payload
        // Using the flat ZnunyAgentList /TicketClose contract
        $payload = [
            'Kind' => 'internal_note',
            'Subject' => 'Automatic ticket close',
            'Body' => 'Closed automatically by Zabbix Znuny Integration after the linked Zabbix problem remained resolved.',
            'Reason' => 'Automatic ticket close after linked Zabbix problem remained resolved.',
        ];

        // 3. Call Znuny controlled close endpoint
        $response = null;
        $apiError = null;

        try {
            $response = $this->znunyClient->closeTicket($ticket->znuny_ticket_id, $payload);
        } catch (\Throwable $e) {
            $apiError = $e->getMessage();
        }

        // If response clearly contains Error (either thrown exception or explicitly in response), treat as failure
        if ($apiError && str_starts_with($apiError, 'Znuny API Error:')) {
            return [
                'success' => false,
                'ticket_number' => $ticket->znuny_ticket_number,
                'skipped' => false,
                'reason' => 'Failed to close in Znuny: '.$apiError,
            ];
        }

        if ($response && ! empty($response['errors'])) {
            return [
                'success' => false,
                'ticket_number' => $ticket->znuny_ticket_number,
                'skipped' => false,
                'reason' => 'Failed to close in Znuny: '.implode(', ', $response['errors']),
            ];
        }

        // 4. Perform read-after-write verification
        try {
            $ticketSnapshot = $this->znunyClient->getTicket($ticket->znuny_ticket_id);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'ticket_number' => $ticket->znuny_ticket_number,
                'skipped' => false,
                'reason' => 'Verification failed: '.$e->getMessage(),
            ];
        }

        $stateType = $ticketSnapshot['StateType'] ?? null;
        $stateName = $ticketSnapshot['State'] ?? null;

        $isClosed = $stateType === 'closed' || str_contains(strtolower((string) $stateName), 'closed');

        if (! $isClosed) {
            return [
                'success' => false,
                'ticket_number' => $ticket->znuny_ticket_number,
                'skipped' => false,
                'reason' => 'Ticket is still open after close attempt.',
            ];
        }

        // 5. Update local DB safely on verified success
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
            'skipped' => false,
            'reason' => 'Closed successfully.',
        ];
    }
}
