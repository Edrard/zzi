<?php

namespace App\Services\Znuny;

use App\Models\ZabbixTicket;
use Illuminate\Support\Facades\Log;

class ZnunyLinkedTicketReopenService
{
    public function __construct(
        protected ZnunyClient $client,
        protected ZnunyTicketSnapshotNormalizer $normalizer
    ) {}

    /**
     * @return array{success: bool, reason?: string, raw?: array}
     */
    public function reopenTicket(ZabbixTicket $ticket, string $reason, string $subject = 'Manual reopen from Zabbix integration', string $kind = 'internal_note'): array
    {
        if ($ticket->manual_lifecycle_status !== ZnunyManualTicketLifecycleService::STATUS_REOPEN_CANDIDATE) {
            return [
                'success' => false,
                'reason' => 'Ticket is not a valid manual reopen candidate.',
            ];
        }

        $payload = [
            'TicketID' => $ticket->znuny_ticket_id,
            'Reason' => $reason,
            'Kind' => $kind,
            'Subject' => $subject,
            'Body' => $reason,
        ];

        try {
            $response = $this->client->reopenTicket($ticket->znuny_ticket_id, $payload);

            if (! $response['success']) {
                $errorMsg = $response['errors'][0] ?? 'Unknown error from Znuny /TicketReopen API';
                Log::warning("Failed to reopen ticket {$ticket->znuny_ticket_number} in Znuny.", [
                    'ticket_id' => $ticket->id,
                    'errors' => $response['errors'],
                    'warnings' => $response['warnings'],
                ]);

                return [
                    'success' => false,
                    'reason' => $errorMsg,
                    'raw' => $response['raw'],
                ];
            }

            // Successfully reopened in Znuny. Update local status.
            $ticket->manual_lifecycle_status = ZnunyManualTicketLifecycleService::STATUS_REOPENED;
            $ticket->manual_reopened_at = now();
            $ticket->zabbix_problem_is_active = true;
            $ticket->manual_close_eligible_at = null;
            // keep flap fields unchanged

            // If response includes State/StateType, update local Znuny state fields
            if (! empty($response['raw']['State']) || ! empty($response['raw']['StateType'])) {
                if (! empty($response['raw']['State'])) {
                    $ticket->znuny_state_name = $response['raw']['State'];
                }
                if (! empty($response['raw']['StateType'])) {
                    $ticket->znuny_ticket_state_type = $response['raw']['StateType'];
                }
            }

            $ticket->save();

            Log::info("Successfully reopened ticket {$ticket->znuny_ticket_number} in Znuny.", [
                'ticket_id' => $ticket->id,
            ]);

            return [
                'success' => true,
                'raw' => $response['raw'],
            ];

        } catch (\Throwable $e) {
            Log::error("Exception reopening ticket {$ticket->znuny_ticket_number}: ".$e->getMessage(), [
                'ticket_id' => $ticket->id,
                'exception' => $e,
            ]);

            return [
                'success' => false,
                'reason' => 'Exception during reopen: '.$e->getMessage(),
            ];
        }
    }
}
