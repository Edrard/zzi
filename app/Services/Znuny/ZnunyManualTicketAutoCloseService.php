<?php

namespace App\Services\Znuny;

use App\Models\ZabbixTicket;

class ZnunyManualTicketAutoCloseService
{
    public function __construct(
        private ZnunyLinkedTicketCloseService $closeService,
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

        $subject = 'Automatic ticket close';
        $body = 'Closed automatically by Zabbix Znuny Integration after the linked Zabbix problem remained resolved.';
        $reason = 'Automatic ticket close after linked Zabbix problem remained resolved.';

        $result = $this->closeService->closeTicket($ticket, $subject, $body, $reason);

        return array_merge($result, ['skipped' => false]);
    }
}
