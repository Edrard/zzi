<?php

namespace App\Services\Znuny;

use App\Models\ZabbixTicket;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;

class ZnunyManualTicketCloseCandidateService
{
    /**
     * Get review report of close candidates.
     */
    public function review(?int $ticketId = null): array
    {
        $query = ZabbixTicket::whereNotNull('znuny_ticket_id');

        if ($ticketId) {
            $query->where('id', $ticketId);
        }

        $tickets = $query->get();

        $autoCloseEnabled = SettingsService::bool('manual_ticket_auto_close_enabled', false);

        $report = [
            'candidates' => [],
            'summary' => [
                'scanned' => 0,
                'candidates' => 0,
                'skipped_closed' => 0,
                'skipped_not_manual' => 0,
                'skipped_not_candidate' => 0,
                'skipped_cache_stale' => 0,
                'skipped_auto_close_disabled' => 0,
                'skipped_future_eligibility' => 0,
            ],
        ];

        $now = Carbon::now();

        foreach ($tickets as $ticket) {
            $report['summary']['scanned']++;

            if ($ticket->creation_source !== 'manual') {
                $report['summary']['skipped_not_manual']++;

                continue;
            }

            if ($ticket->znuny_ticket_state_type === 'closed') {
                $report['summary']['skipped_closed']++;

                continue;
            }

            if ($ticket->manual_lifecycle_status === ZnunyManualTicketLifecycleService::STATUS_CACHE_STALE) {
                $report['summary']['skipped_cache_stale']++;

                continue;
            }

            if ($ticket->manual_lifecycle_status !== ZnunyManualTicketLifecycleService::STATUS_CLOSE_CANDIDATE) {
                $report['summary']['skipped_not_candidate']++;

                continue;
            }

            if (! $autoCloseEnabled) {
                $report['summary']['skipped_auto_close_disabled']++;

                continue;
            }

            if (! $ticket->manual_close_eligible_at || $ticket->manual_close_eligible_at->greaterThan($now)) {
                $report['summary']['skipped_future_eligibility']++;

                continue;
            }

            // Ticket is a valid close candidate
            $report['summary']['candidates']++;
            $report['candidates'][] = [
                'id' => $ticket->id,
                'ticket_number' => $ticket->znuny_ticket_number,
                'host' => $ticket->zabbix_host_name,
                'problem' => $ticket->zabbix_problem_name,
                'znuny_state' => $ticket->znuny_ticket_state_type,
                'lifecycle_status' => $ticket->manual_lifecycle_status,
                'resolved_since' => $ticket->zabbix_problem_resolved_at ? $ticket->zabbix_problem_resolved_at->toDateTimeString() : null,
                'close_eligible_at' => $ticket->manual_close_eligible_at ? $ticket->manual_close_eligible_at->toDateTimeString() : null,
                'flap_count' => $ticket->manual_flap_count,
                'reason' => 'Problem resolved since '.($ticket->zabbix_problem_resolved_at ? $ticket->zabbix_problem_resolved_at->format('Y-m-d H:i') : 'unknown').', close delay elapsed, ticket still open, cache fresh.',
            ];
        }

        return $report;
    }
}
