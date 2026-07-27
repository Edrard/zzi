<?php

namespace App\Services\Znuny;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\SettingsService;
use Carbon\Carbon;
use Throwable;

final class ScheduledZnunyTicketMarkerRefreshLookupService
{
    public function __construct(
        private readonly ScheduledZnunyTicketMarkerLookupService $markerLookup,
        private readonly ZnunyClient $client
    ) {}

    public function findExactMarkerWithRefresh(ZnunyTicketCreationAttempt $attempt): array
    {
        $marker = trim((string) $attempt->marker);
        $initialResult = $this->markerLookup->findExactMarker($marker);

        if ($initialResult['status'] !== ScheduledZnunyTicketMarkerLookupStatus::NotFound &&
            $initialResult['status'] !== ScheduledZnunyTicketMarkerLookupStatus::Unavailable) {
            $initialResult['refresh_attempted'] = false;
            $initialResult['refresh_succeeded'] = false;
            $initialResult['refresh_exit_code'] = null;

            return $initialResult;
        }

        return $this->executeDirectApiSearch($attempt, $marker);
    }

    public function refreshAndFindExactMarker(ZnunyTicketCreationAttempt $attempt): array
    {
        $marker = trim((string) $attempt->marker);

        return $this->executeDirectApiSearch($attempt, $marker);
    }

    private function executeDirectApiSearch(ZnunyTicketCreationAttempt $attempt, string $marker): array
    {
        $unavailable = [
            'status' => ScheduledZnunyTicketMarkerLookupStatus::Unavailable,
            'match_count' => 0,
            'ticket_id' => null,
            'ticket_number' => null,
            'matches' => [],
            'reason' => 'Direct API fallback search failed or was malformed.',
            'refresh_attempted' => false,
            'refresh_succeeded' => false,
            'refresh_exit_code' => null,
        ];

        $marker = trim($marker);
        if ($marker === '') {
            $unavailable['reason'] = 'Cannot perform API fallback because attempt marker is empty.';

            return $unavailable;
        }

        $anchorTime = $this->determineAnchorTime($attempt);
        if (! $anchorTime) {
            $unavailable['reason'] = 'Cannot perform API fallback because attempt lacks a valid anchor time.';

            return $unavailable;
        }

        $znunyTimezone = SettingsService::string('app_display_timezone', config('app.timezone', 'UTC'));
        if (empty($znunyTimezone)) {
            $unavailable['reason'] = 'Cannot perform API fallback because the configured Znuny timezone is invalid.';

            return $unavailable;
        }

        try {
            new \DateTimeZone($znunyTimezone);
        } catch (Throwable) {
            $unavailable['reason'] = 'Cannot perform API fallback because the configured Znuny timezone is invalid.';

            return $unavailable;
        }

        $anchorTime->setTimezone('UTC');

        try {
            $filters = [
                'Title' => "*{$marker}*",
                'CreatedFrom' => $anchorTime->copy()->subHours(24)->setTimezone($znunyTimezone)->format('Y-m-d H:i:s'),
                'CreatedTo' => $anchorTime->copy()->addHours(24)->setTimezone($znunyTimezone)->format('Y-m-d H:i:s'),
                'Limit' => 2,
                'Offset' => 0,
            ];

            $unavailable['refresh_attempted'] = true;
            $response = $this->client->searchTicketsWithMetadata($filters);

            if (! empty($response['warnings'])) {
                $unavailable['reason'] = 'Direct API fallback encountered Znuny warnings.';

                return $unavailable;
            }

            if (! isset($response['tickets']) || ! is_array($response['tickets'])) {
                $unavailable['reason'] = 'Direct API fallback returned malformed ticket data.';

                return $unavailable;
            }

            // Reuse the exact marker matching rule.
            $matches = [];
            foreach ($response['tickets'] as $ticket) {
                $ticketId = isset($ticket['TicketID']) && is_numeric($ticket['TicketID']) ? (int) $ticket['TicketID'] : 0;
                $ticketNumber = isset($ticket['TicketNumber']) ? trim((string) $ticket['TicketNumber']) : '';
                $title = isset($ticket['Title']) ? (string) $ticket['Title'] : '';

                if ($ticketId <= 0 || $ticketNumber === '' || $title === '') {
                    $unavailable['reason'] = 'Direct API fallback returned tickets without valid identifiers or title.';

                    return $unavailable;
                }

                if (str_contains($title, $marker)) {
                    $matches[] = [
                        'ticket_id' => $ticketId,
                        'ticket_number' => $ticketNumber,
                        'title' => $title,
                        'state' => isset($ticket['State']) ? (string) $ticket['State'] : null,
                        'state_type' => isset($ticket['StateType']) ? (string) $ticket['StateType'] : null,
                        'queue' => isset($ticket['Queue']) ? (string) $ticket['Queue'] : null,
                    ];
                }
            }

            $matchCount = count($matches);
            $status = ScheduledZnunyTicketMarkerLookupStatus::NotFound;
            $ticketId = null;
            $ticketNumber = null;

            if ($matchCount === 1) {
                $status = ScheduledZnunyTicketMarkerLookupStatus::Found;
                $ticketId = $matches[0]['ticket_id'];
                $ticketNumber = $matches[0]['ticket_number'];
            } elseif ($matchCount > 1) {
                $status = ScheduledZnunyTicketMarkerLookupStatus::Multiple;
            }

            return [
                'status' => $status,
                'match_count' => $matchCount,
                'ticket_id' => $ticketId,
                'ticket_number' => $ticketNumber,
                'matches' => $matches,
                'reason' => $matchCount === 0 ? 'No matching ticket found via direct API search.' : null,
                'refresh_attempted' => true,
                'refresh_succeeded' => true,
                'refresh_exit_code' => null,
            ];

        } catch (Throwable) {
            $unavailable['reason'] = 'Direct API fallback threw an exception.';

            return $unavailable;
        }
    }

    private function determineAnchorTime(ZnunyTicketCreationAttempt $attempt): ?Carbon
    {
        if ($attempt->started_at) {
            return Carbon::parse($attempt->started_at);
        }

        $run = ScheduledZnunyTaskRun::find($attempt->source_id);
        if ($run) {
            if ($run->started_at) {
                return Carbon::parse($run->started_at);
            }
            if ($run->scheduled_for) {
                return Carbon::parse($run->scheduled_for);
            }
        }

        if ($attempt->created_at) {
            return Carbon::parse($attempt->created_at);
        }

        return null;
    }
}
