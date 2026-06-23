<?php

namespace App\Services\Zabbix;

use App\Models\ZabbixTicket;

class ZabbixTicketStatusPresenter
{
    /**
     * Get the indicator array used in Current Zabbix Problems page rows.
     */
    public static function problemIndicator(?ZabbixTicket $ticket): ?array
    {
        if (! $ticket) {
            return null;
        }

        if (in_array($ticket->manual_lifecycle_status, [
            'closed',
            'not_applicable',
            'cache_stale',
            'identity_missing',
        ], true)) {
            return null;
        }

        if ($ticket->manual_lifecycle_status === 'flapping') {
            return [
                'kind' => 'flapping',
                'icon' => 'heroicon-o-exclamation-triangle',
                'class' => 'zbx-status-icon-flapping',
                'style' => '',
                'title' => 'Flapping ticket. Ticket: '.$ticket->znuny_ticket_number,
            ];
        }

        if ($ticket->manual_lifecycle_status === 'reopen_candidate') {
            return [
                'kind' => 'reopen_candidate',
                'icon' => 'heroicon-o-arrow-path',
                'class' => 'zbx-status-icon-reopen-candidate',
                'style' => '',
                'title' => 'Manual reopen candidate. Ticket: '.$ticket->znuny_ticket_number,
            ];
        }

        if ($ticket->manual_lifecycle_status === 'reopened' || $ticket->manual_reopened_at !== null) {
            return [
                'kind' => 'reopened',
                'icon' => 'heroicon-o-arrow-uturn-left',
                'class' => 'zbx-status-icon-reopened',
                'style' => '',
                'title' => 'Manually reopened ticket. Ticket: '.$ticket->znuny_ticket_number,
            ];
        }

        return [
            'kind' => 'linked',
            'icon' => 'heroicon-o-ticket',
            'class' => 'zbx-status-icon-linked',
            'style' => '',
            'title' => 'Ticket already linked: '.$ticket->znuny_ticket_number,
        ];
    }

    /**
     * Get items for the icon legend at the bottom of the page.
     */
    public static function legendItems(): array
    {
        return [
            [
                'icon' => 'heroicon-o-ticket',
                'class' => 'zbx-status-icon-linked',
                'label' => 'Linked ticket',
                'description' => 'A Znuny ticket is currently linked to this active Zabbix problem.',
            ],
            [
                'icon' => 'heroicon-o-arrow-path',
                'class' => 'zbx-status-icon-reopen-candidate',
                'label' => 'Manual reopen candidate',
                'description' => 'The linked ticket is closed, but the Zabbix problem is active again. Review manually.',
            ],
            [
                'icon' => 'heroicon-o-arrow-uturn-left',
                'class' => 'zbx-status-icon-reopened',
                'label' => 'Manually reopened',
                'description' => 'The ticket was manually reopened by an operator.',
            ],
            [
                'icon' => 'heroicon-o-exclamation-triangle',
                'class' => 'zbx-status-icon-flapping',
                'label' => 'Flapping detected',
                'description' => 'This problem has resolved and become active again multiple times recently.',
            ],
        ];
    }

    /**
     * Presentation data for the Linked Tickets table Zabbix status column
     * and the ZabbixTicketInfolist Context badge.
     */
    public static function getLifecyclePresentation(ZabbixTicket $record): array
    {
        if ($record->manual_lifecycle_status === 'flapping') {
            return [
                'label' => 'Flapping',
                'color' => 'danger',
                'icon' => 'heroicon-o-exclamation-triangle',
                'tooltip' => 'Flapping problem detected.',
            ];
        }

        if ($record->manual_lifecycle_status === 'reopen_candidate') {
            return [
                'label' => 'Manual reopen candidate',
                'color' => 'warning',
                'icon' => 'heroicon-o-arrow-path',
                'tooltip' => 'The Znuny ticket is closed, but the linked Zabbix problem is active again within the reopen window. Review manually.',
            ];
        }

        if ($record->manual_lifecycle_status === 'reopened' || $record->manual_reopened_at !== null) {
            return [
                'label' => 'Reopened',
                'color' => 'info',
                'icon' => 'heroicon-o-arrow-uturn-left',
                'tooltip' => 'Manually reopened ticket.',
            ];
        }

        if (self::isClosed($record)) {
            return [
                'label' => 'Closed',
                'color' => 'gray',
                'icon' => 'heroicon-o-check-circle',
                'tooltip' => null,
            ];
        }

        if ($record->manual_lifecycle_status === 'close_candidate') {
            return [
                'label' => 'Ready',
                'color' => 'success',
                'icon' => 'heroicon-o-check-circle',
                'tooltip' => 'Linked Zabbix problem is resolved and close delay has passed.',
            ];
        }

        if ($record->manual_lifecycle_status === 'resolved_waiting') {
            return [
                'label' => 'Waiting for close delay', // Table had 'Waiting', Infolist had 'Waiting for close delay'. I'll stick to 'Waiting' for brevity in both if they share. Let's use 'Waiting' for badge.
                'color' => 'info',
                'icon' => 'heroicon-o-check',
                'tooltip' => 'Linked Zabbix problem is resolved, waiting for close delay.',
            ];
        }

        if ($record->manual_lifecycle_status === 'cache_stale') {
            return [
                'label' => 'Cache stale',
                'color' => 'warning',
                'icon' => 'heroicon-o-clock',
                'tooltip' => 'Zabbix problem cache may be stale. Waiting for sync.',
            ];
        }

        if ($record->manual_lifecycle_status === 'identity_missing') {
            return [
                'label' => 'Missing Zabbix identity',
                'color' => 'warning',
                'icon' => 'heroicon-o-question-mark-circle', // Table used question mark for fallback
                'tooltip' => 'Missing Zabbix host/trigger identity; lifecycle cannot be evaluated safely.',
            ];
        }

        if ($record->manual_lifecycle_status === 'active' || $record->zabbix_problem_is_active === true) {
            return [
                'label' => 'Active', // Table: Active, Infolist: Zabbix problem active
                'color' => 'danger',
                'icon' => 'heroicon-o-exclamation-circle',
                'tooltip' => 'Linked Zabbix problem is still active.',
            ];
        }

        return [
            'label' => 'Unknown',
            'color' => 'gray',
            'icon' => 'heroicon-o-question-mark-circle',
            'tooltip' => 'Lifecycle state has not been evaluated yet.',
        ];
    }

    /**
     * Determines if a ticket is considered fully closed in Znuny
     * based on its synced state.
     */
    public static function isClosed(ZabbixTicket $record): bool
    {
        if (strtolower($record->znuny_ticket_state_type ?? '') === 'closed') {
            return true;
        }

        if (str_contains(strtolower((string) $record->znuny_state_name), 'closed')) {
            return true;
        }

        return false;
    }
}
