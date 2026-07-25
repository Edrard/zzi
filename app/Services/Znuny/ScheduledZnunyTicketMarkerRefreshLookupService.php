<?php

namespace App\Services\Znuny;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use Illuminate\Contracts\Console\Kernel;

final class ScheduledZnunyTicketMarkerRefreshLookupService
{
    public function __construct(
        private readonly ScheduledZnunyTicketMarkerLookupService $markerLookup,
        private readonly Kernel $console
    ) {}

    public function findExactMarkerWithRefresh(string $marker): array
    {
        $initialResult = $this->markerLookup->findExactMarker($marker);

        if ($initialResult['status'] !== ScheduledZnunyTicketMarkerLookupStatus::NotFound) {
            $initialResult['refresh_attempted'] = false;
            $initialResult['refresh_succeeded'] = false;
            $initialResult['refresh_exit_code'] = null;

            return $initialResult;
        }

        try {
            $exitCode = $this->console->call(
                'znuny:warm-ticket-workspace-cache',
                ['--manual' => true]
            );
        } catch (\Throwable) {
            return [
                'status' => ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                'match_count' => 0,
                'ticket_id' => null,
                'ticket_number' => null,
                'matches' => [],
                'reason' => 'Failed to refresh the active Znuny Ticket Workspace cache.',
                'refresh_attempted' => true,
                'refresh_succeeded' => false,
                'refresh_exit_code' => null,
            ];
        }

        if ($exitCode !== 0) {
            return [
                'status' => ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
                'match_count' => 0,
                'ticket_id' => null,
                'ticket_number' => null,
                'matches' => [],
                'reason' => 'Failed to refresh the active Znuny Ticket Workspace cache.',
                'refresh_attempted' => true,
                'refresh_succeeded' => false,
                'refresh_exit_code' => $exitCode,
            ];
        }

        $secondResult = $this->markerLookup->findExactMarker($marker);

        $secondResult['refresh_attempted'] = true;
        $secondResult['refresh_succeeded'] = true;
        $secondResult['refresh_exit_code'] = 0;

        return $secondResult;
    }
}
