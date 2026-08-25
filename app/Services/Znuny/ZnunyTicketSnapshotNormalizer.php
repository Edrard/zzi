<?php

namespace App\Services\Znuny;

use Illuminate\Support\Carbon;

class ZnunyTicketSnapshotNormalizer
{
    public function normalize(array $ticketData): array
    {
        $normalized = [
            'znuny_ticket_number' => $ticketData['TicketNumber'] ?? null,
            'znuny_queue_id' => isset($ticketData['QueueID']) ? (int) $ticketData['QueueID'] : null,
            'znuny_queue_name' => $ticketData['Queue'] ?? null,
            'znuny_owner_id' => isset($ticketData['OwnerID']) ? (int) $ticketData['OwnerID'] : null,
            'znuny_owner_name' => $ticketData['Owner'] ?? null,
            'znuny_state_id' => isset($ticketData['StateID']) ? (int) $ticketData['StateID'] : null,
            'znuny_state_name' => $ticketData['State'] ?? null,
            'znuny_ticket_state_type' => $ticketData['StateType'] ?? null,
            'znuny_priority_id' => isset($ticketData['PriorityID']) ? (int) $ticketData['PriorityID'] : null,
            'znuny_priority' => $ticketData['Priority'] ?? null,
            'znuny_ticket_changed_at' => null,
            'znuny_ticket_closed_at' => null,
        ];

        if (! empty($ticketData['Changed'])) {
            try {
                $normalized['znuny_ticket_changed_at'] = Carbon::parse($ticketData['Changed'])->toDateTimeString();
            } catch (\Exception $e) {
                // Ignore parse error
            }
        }

        // Search for possible close time fields, but do not assume "Changed" is close time.
        $closeTimeFields = ['Closed', 'ClosedAt', 'ClosedTime', 'CloseTime', 'CloseTimestamp'];
        foreach ($closeTimeFields as $field) {
            if (! empty($ticketData[$field])) {
                try {
                    $normalized['znuny_ticket_closed_at'] = Carbon::parse($ticketData[$field])->toDateTimeString();
                    break;
                } catch (\Exception $e) {
                    // Ignore parse error
                }
            }
        }

        return $normalized;
    }

    public function hash(array $normalized): string
    {
        ksort($normalized);

        return md5(json_encode($normalized));
    }
}
