<?php

namespace App\Services\Znuny;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;

final class ScheduledZnunyTicketMarkerLookupService
{
    public function __construct(
        private readonly ZnunyTicketWorkspaceCacheReader $cacheReader
    ) {}

    public function findExactMarker(string $marker): array
    {
        $marker = trim($marker);

        if ($marker === '') {
            return [
                'status' => ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                'match_count' => 0,
                'ticket_id' => null,
                'ticket_number' => null,
                'matches' => [],
                'reason' => 'Scheduled ticket marker is empty.',
            ];
        }

        try {
            $activeStateTypes = ZnunyTicketWorkspaceStateTypeMapper::idsToZnunyStateTypes([
                'new',
                'open',
                'pending_reminder',
                'pending_auto',
            ]);

            $tickets = $this->cacheReader->getTickets([
                'state_types' => $activeStateTypes,
            ]);
        } catch (\Throwable) {
            return [
                'status' => ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                'match_count' => 0,
                'ticket_id' => null,
                'ticket_number' => null,
                'matches' => [],
                'reason' => 'Failed to read Ticket Workspace cache.',
            ];
        }

        $openMatches = [];
        $invalidOpenMatches = 0;

        foreach ($tickets as $ticket) {
            $stateType = strtolower((string) ($ticket['StateType'] ?? ''));

            $isActive = false;
            foreach ($activeStateTypes as $activeType) {
                if ($stateType === strtolower((string) $activeType)) {
                    $isActive = true;
                    break;
                }
            }

            if (! $isActive) {
                continue;
            }

            $title = (string) ($ticket['Title'] ?? '');

            if (str_contains($title, $marker)) {
                $ticketId = $ticket['TicketID'] ?? null;
                $ticketNumber = isset($ticket['TicketNumber']) ? trim((string) $ticket['TicketNumber']) : '';

                $isValid = $ticketId !== null
                    && is_numeric($ticketId)
                    && $ticketId > 0
                    && $ticketNumber !== '';

                if ($isValid) {
                    $openMatches[] = [
                        'ticket_id' => $ticketId,
                        'ticket_number' => $ticketNumber,
                    ];
                } else {
                    $invalidOpenMatches++;
                }
            }
        }

        usort($openMatches, function ($a, $b) {
            $idA = $a['ticket_id'];
            $idB = $b['ticket_id'];

            if (is_numeric($idA) && is_numeric($idB)) {
                $idCmp = $idA <=> $idB;
            } else {
                $idCmp = strcmp((string) $idA, (string) $idB);
            }

            if ($idCmp !== 0) {
                return $idCmp;
            }

            return strcmp((string) $a['ticket_number'], (string) $b['ticket_number']);
        });

        if ($invalidOpenMatches > 0) {
            return [
                'status' => ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                'match_count' => count($openMatches) + $invalidOpenMatches,
                'ticket_id' => null,
                'ticket_number' => null,
                'matches' => $openMatches,
                'reason' => 'A matching open Znuny ticket has invalid identifiers.',
            ];
        }

        $matchCount = count($openMatches);

        if ($matchCount === 0) {
            return [
                'status' => ScheduledZnunyTicketMarkerLookupStatus::NotFound,
                'match_count' => 0,
                'ticket_id' => null,
                'ticket_number' => null,
                'matches' => [],
                'reason' => null,
            ];
        }

        if ($matchCount === 1) {
            return [
                'status' => ScheduledZnunyTicketMarkerLookupStatus::Found,
                'match_count' => 1,
                'ticket_id' => $openMatches[0]['ticket_id'],
                'ticket_number' => $openMatches[0]['ticket_number'],
                'matches' => $openMatches,
                'reason' => null,
            ];
        }

        return [
            'status' => ScheduledZnunyTicketMarkerLookupStatus::Multiple,
            'match_count' => $matchCount,
            'ticket_id' => null,
            'ticket_number' => null,
            'matches' => $openMatches,
            'reason' => 'Multiple open Znuny tickets contain the scheduled marker.',
        ];
    }
}
