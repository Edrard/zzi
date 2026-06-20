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
        // Using conservative 'closed successful' as instructed, with minimal notes.
        $payload = [
            'State' => 'closed successful',
            'Article' => [
                'Kind' => 'internal_note',
                'Subject' => 'Automatic ticket close',
                'Body' => 'Closed automatically by Zabbix Znuny Integration after the linked Zabbix problem remained resolved.',
                'ContentType' => 'text/plain; charset=utf8',
            ],
        ];

        // 3. Call Znuny controlled close endpoint
        try {
            $response = $this->znunyClient->closeTicket($ticket->znuny_ticket_id, $payload);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'ticket_number' => $ticket->znuny_ticket_number,
                'skipped' => false,
                'reason' => 'API Error: '.$e->getMessage(),
            ];
        }

        if (! $response['success']) {
            $errors = implode(', ', $response['errors'] ?? ['Unknown close error']);

            return [
                'success' => false,
                'ticket_number' => $ticket->znuny_ticket_number,
                'skipped' => false,
                'reason' => 'Failed to close in Znuny: '.$errors,
            ];
        }

        // 4. Update local DB safely on success
        $ticket->update([
            'manual_lifecycle_status' => ZnunyManualTicketLifecycleService::STATUS_CLOSED,
            'manual_lifecycle_last_checked_at' => now(),
            // Optionally optimistic snapshot update, let sync job handle the rest.
            'znuny_ticket_state_type' => 'closed',
            'znuny_state_name' => 'closed successful',
        ]);

        return [
            'success' => true,
            'ticket_number' => $ticket->znuny_ticket_number,
            'skipped' => false,
            'reason' => 'Closed successfully.',
        ];
    }
}
