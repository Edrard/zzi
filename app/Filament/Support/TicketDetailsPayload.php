<?php

namespace App\Filament\Support;

use App\Models\ZabbixTicket;
use App\Services\Zabbix\ZabbixTicketStatusPresenter;
use Illuminate\Support\Carbon;

class TicketDetailsPayload
{
    public bool $is_zabbix_ticket = false;

    public bool $is_workspace = false;

    public ?string $znuny_ticket_id = null;

    public ?int $zabbix_ticket_id = null;

    public ?string $znuny_ticket_number = null;

    public ?string $title = null;

    public ?Carbon $created_at = null;

    public ?Carbon $changed_at = null;

    public ?Carbon $manual_reopened_at = null;

    public array $resolution_context = [];

    public ?Carbon $zabbix_problem_resolved_at = null;

    public ?Carbon $manual_close_eligible_at = null;

    public ?Carbon $znuny_ticket_closed_at = null;

    public int $manual_flap_count = 0;

    public ?Carbon $manual_last_flap_counted_at = null;

    public ?string $zabbix_host_name = null;

    public ?string $zabbix_problem_name = null;

    public ?string $zabbix_event_id = null;

    public ?string $znuny_queue_name = null;

    public ?string $znuny_owner_name = null;

    public ?string $customer_user = null;

    public ?string $znuny_priority = null;

    public ?string $znuny_state_name = null;

    public ?string $state_type_str = null;

    public ?string $ticket_type = null;

    public ?int $lock_id = null;

    public ?string $lock = null;

    public ?int $article_count = null;

    public ?Carbon $last_article = null;

    public ?Carbon $znuny_ticket_last_checked_at = null;

    public ?Carbon $znuny_ticket_last_synced_at = null;

    public ?string $znuny_ticket_sync_error = null;

    public bool $has_zabbix_link = false;

    public bool $has_sync_section = false;

    public bool $is_open = false;

    public bool $is_closed = false;

    public static function fromRecord(mixed $record, array $arguments = []): self
    {
        $payload = new self;

        if (empty($record) && ! empty($arguments['zabbix_ticket_id'])) {
            $record = ZabbixTicket::find($arguments['zabbix_ticket_id']);
        }

        if ($record instanceof ZabbixTicket) {
            $payload->is_zabbix_ticket = true;
            $payload->title = $record->zabbix_problem_name;
            $payload->znuny_ticket_id = $record->znuny_ticket_id;
            $payload->zabbix_ticket_id = $record->id;
            $payload->znuny_ticket_number = $record->znuny_ticket_number;
            $payload->created_at = $record->created_at;
            $payload->manual_reopened_at = $record->manual_reopened_at;
            $payload->resolution_context = ZabbixTicketStatusPresenter::getLifecyclePresentation($record);
            $payload->zabbix_problem_resolved_at = $record->zabbix_problem_resolved_at;
            $payload->manual_close_eligible_at = $record->manual_close_eligible_at;
            $payload->znuny_ticket_closed_at = $record->znuny_ticket_closed_at;
            $payload->manual_flap_count = $record->manual_flap_count;
            $payload->manual_last_flap_counted_at = $record->manual_last_flap_counted_at;
            $payload->zabbix_host_name = $record->zabbix_host_name;
            $payload->zabbix_problem_name = $record->zabbix_problem_name;
            $payload->zabbix_event_id = $record->zabbix_event_id;
            $payload->znuny_queue_name = $record->znuny_queue_name;
            $payload->znuny_owner_name = $record->znuny_owner_name;
            $payload->znuny_priority = $record->znuny_priority;
            $payload->znuny_state_name = $record->znuny_state_name;
            $payload->state_type_str = $record->znuny_ticket_state_type;
            $payload->znuny_ticket_last_checked_at = $record->znuny_ticket_last_checked_at;
            $payload->znuny_ticket_last_synced_at = $record->znuny_ticket_last_synced_at;
            $payload->znuny_ticket_sync_error = $record->znuny_ticket_sync_error;
            $payload->has_zabbix_link = true;
            $payload->has_sync_section = true;

            $payload->is_closed = strtolower($record->znuny_ticket_state_type ?? '') === 'closed';
            $payload->is_open = ! $payload->is_closed;

            return $payload;
        }

        $arr = (array) $record;

        $hasLink = ! empty($arr['is_linked_to_zabbix_problem']);
        $isActive = ! empty($arr['linked_problem_is_active']);

        $payload->is_workspace = true;
        $payload->znuny_ticket_number = $arr['TicketNumber'] ?? null;
        $payload->title = $arr['Title'] ?? ($arr['linked_problem_summary'] ?? null);

        // Normalize Znuny workspace strings to Carbon objects, but no formatting here.
        // It stays as Carbon to be formatted safely in the layout.
        // In the workspace, we receive string timestamps (like "2026-06-29 10:00:00").
        // ZabbixTicket models return Carbon objects, so we match that type.
        $payload->created_at = ! empty($arr['Created']) ? Carbon::parse($arr['Created']) : null;
        $payload->changed_at = ! empty($arr['Changed']) ? Carbon::parse($arr['Changed']) : null;

        $payload->resolution_context = [
            'label' => $hasLink ? ($isActive ? 'Active Problem' : 'Resolved Problem') : 'Not Linked',
            'color' => $hasLink ? ($isActive ? 'warning' : 'success') : 'gray',
            'icon' => $hasLink ? ($isActive ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle') : 'heroicon-o-minus',
            'tooltip' => '',
        ];

        $payload->zabbix_host_name = $arr['linked_problem_host'] ?? null;
        $payload->zabbix_problem_name = $arr['linked_problem_summary'] ?? null;
        $payload->zabbix_event_id = $arr['linked_problem_event_id'] ?? null;
        $payload->znuny_queue_name = $arr['Queue'] ?? null;
        $payload->znuny_owner_name = $arr['Owner'] ?? null;
        $payload->customer_user = $arr['CustomerUserID'] ?? null;
        $payload->znuny_priority = $arr['Priority'] ?? null;
        $payload->znuny_state_name = $arr['State'] ?? null;
        $payload->state_type_str = $arr['StateType'] ?? null;
        $payload->ticket_type = $arr['Type'] ?? null;
        $payload->lock_id = isset($arr['LockID']) ? (int) $arr['LockID'] : null;
        $payload->lock = $arr['Lock'] ?? null;
        $payload->article_count = isset($arr['ArticleCount']) ? (int) $arr['ArticleCount'] : null;
        $payload->last_article = ! empty($arr['LastArticleCreated']) ? Carbon::parse($arr['LastArticleCreated']) : null;
        $payload->has_zabbix_link = $hasLink;

        // Also capture the actual ticket ID if we have it in the array or arguments
        $payload->znuny_ticket_id = $arr['TicketID'] ?? $arguments['znuny_ticket_id'] ?? null;

        $payload->is_closed = strtolower($payload->state_type_str ?? '') === 'closed';
        $payload->is_open = ! $payload->is_closed;

        return $payload;
    }
}
