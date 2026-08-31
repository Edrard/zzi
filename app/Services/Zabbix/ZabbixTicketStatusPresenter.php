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
                'title' => __('zabbix_tickets.status_presenter.titles.flapping_ticket', ['ticket' => $ticket->znuny_ticket_number]),
            ];
        }

        if ($ticket->manual_lifecycle_status === 'reopen_candidate') {
            return [
                'kind' => 'reopen_candidate',
                'icon' => 'heroicon-o-arrow-path',
                'class' => 'zbx-status-icon-reopen-candidate',
                'style' => '',
                'title' => __('zabbix_tickets.status_presenter.titles.manual_reopen_candidate', ['ticket' => $ticket->znuny_ticket_number]),
            ];
        }

        if ($ticket->manual_lifecycle_status === 'reopened' || $ticket->manual_reopened_at !== null) {
            return [
                'kind' => 'reopened',
                'icon' => 'heroicon-o-arrow-uturn-left',
                'class' => 'zbx-status-icon-reopened',
                'style' => '',
                'title' => __('zabbix_tickets.status_presenter.titles.manually_reopened_ticket', ['ticket' => $ticket->znuny_ticket_number]),
            ];
        }

        return [
            'kind' => 'linked',
            'icon' => 'heroicon-o-ticket',
            'class' => 'zbx-status-icon-linked',
            'style' => '',
            'title' => __('zabbix_tickets.status_presenter.titles.ticket_already_linked', ['ticket' => $ticket->znuny_ticket_number]),
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
                'label' => __('zabbix_tickets.status_presenter.labels.linked_ticket'),
                'description' => __('zabbix_tickets.status_presenter.descriptions.linked_ticket'),
            ],
            [
                'icon' => 'heroicon-o-arrow-path',
                'class' => 'zbx-status-icon-reopen-candidate',
                'label' => __('zabbix_tickets.status_presenter.labels.manual_reopen_candidate'),
                'description' => __('zabbix_tickets.status_presenter.descriptions.manual_reopen_candidate'),
            ],
            [
                'icon' => 'heroicon-o-arrow-uturn-left',
                'class' => 'zbx-status-icon-reopened',
                'label' => __('zabbix_tickets.status_presenter.labels.manually_reopened'),
                'description' => __('zabbix_tickets.status_presenter.descriptions.manually_reopened'),
            ],
            [
                'icon' => 'heroicon-o-exclamation-triangle',
                'class' => 'zbx-status-icon-flapping',
                'label' => __('zabbix_tickets.status_presenter.labels.flapping_detected'),
                'description' => __('zabbix_tickets.status_presenter.descriptions.flapping_detected'),
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
                'label' => __('zabbix_tickets.status_presenter.labels.flapping'),
                'color' => 'danger',
                'icon' => 'heroicon-o-exclamation-triangle',
                'tooltip' => __('zabbix_tickets.status_presenter.tooltips.flapping_detected'),
            ];
        }

        if ($record->manual_lifecycle_status === 'reopen_candidate') {
            return [
                'label' => __('zabbix_tickets.status_presenter.labels.manual_reopen_candidate'),
                'color' => 'warning',
                'icon' => 'heroicon-o-arrow-path',
                'tooltip' => __('zabbix_tickets.status_presenter.tooltips.manual_reopen_candidate'),
            ];
        }

        if ($record->manual_lifecycle_status === 'reopened' || $record->manual_reopened_at !== null) {
            return [
                'label' => __('zabbix_tickets.status_presenter.labels.reopened'),
                'color' => 'info',
                'icon' => 'heroicon-o-arrow-uturn-left',
                'tooltip' => __('zabbix_tickets.status_presenter.tooltips.manually_reopened'),
            ];
        }

        if (self::isClosed($record)) {
            return [
                'label' => __('zabbix_tickets.status_presenter.labels.closed'),
                'color' => 'gray',
                'icon' => 'heroicon-o-check-circle',
                'tooltip' => null,
            ];
        }

        if ($record->manual_lifecycle_status === 'close_candidate') {
            return [
                'label' => __('zabbix_tickets.status_presenter.labels.ready'),
                'color' => 'success',
                'icon' => 'heroicon-o-check-circle',
                'tooltip' => __('zabbix_tickets.status_presenter.tooltips.ready_to_close'),
            ];
        }

        if ($record->manual_lifecycle_status === 'resolved_waiting') {
            return [
                'label' => __('zabbix_tickets.status_presenter.labels.waiting_for_close_delay'), // Table had 'Waiting', Infolist had 'Waiting for close delay'. I'll stick to 'Waiting' for brevity in both if they share. Let's use 'Waiting' for badge.
                'color' => 'info',
                'icon' => 'heroicon-o-check',
                'tooltip' => __('zabbix_tickets.status_presenter.tooltips.waiting_for_close_delay'),
            ];
        }

        if ($record->manual_lifecycle_status === 'cache_stale') {
            return [
                'label' => __('zabbix_tickets.status_presenter.labels.cache_stale'),
                'color' => 'warning',
                'icon' => 'heroicon-o-clock',
                'tooltip' => __('zabbix_tickets.status_presenter.tooltips.cache_stale'),
            ];
        }

        if ($record->manual_lifecycle_status === 'identity_missing') {
            return [
                'label' => __('zabbix_tickets.status_presenter.labels.missing_zabbix_identity'),
                'color' => 'warning',
                'icon' => 'heroicon-o-question-mark-circle', // Table used question mark for fallback
                'tooltip' => __('zabbix_tickets.status_presenter.tooltips.missing_zabbix_identity'),
            ];
        }

        if ($record->manual_lifecycle_status === 'active' || $record->zabbix_problem_is_active === true) {
            return [
                'label' => __('zabbix_tickets.status_presenter.labels.active'), // Table: Active, Infolist: Zabbix problem active
                'color' => 'danger',
                'icon' => 'heroicon-o-exclamation-circle',
                'tooltip' => __('zabbix_tickets.status_presenter.tooltips.active'),
            ];
        }

        return [
            'label' => __('zabbix_tickets.status_presenter.labels.unknown'),
            'color' => 'gray',
            'icon' => 'heroicon-o-question-mark-circle',
            'tooltip' => __('zabbix_tickets.status_presenter.tooltips.unknown'),
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
