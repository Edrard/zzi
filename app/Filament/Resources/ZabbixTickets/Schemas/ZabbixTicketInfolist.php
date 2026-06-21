<?php

namespace App\Filament\Resources\ZabbixTickets\Schemas;

use App\Models\ZabbixTicket;
use App\Services\Support\DateTimeDisplayService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ZabbixTicketInfolist
{
    private static function formatLabel(string $label): HtmlString
    {
        return new HtmlString('<span style="color: light-dark(#6b7280, #bbb); font-weight: 400;">'.e($label).'</span>');
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ticket')
                    ->schema([
                        TextEntry::make('znuny_ticket_number')->label(self::formatLabel('Ticket Number'))->inlineLabel()->placeholder('-'),
                        TextEntry::make('created_at')->label(self::formatLabel('Ticket Age'))->since()->inlineLabel()->placeholder('-'),
                        TextEntry::make('resolution_context')
                            ->label(self::formatLabel('Resolution Context'))
                            ->state(function (ZabbixTicket $record) {
                                $isClosed = strtolower($record->znuny_ticket_state_type ?? '') === 'closed' || str_contains(strtolower((string) $record->znuny_state_name), 'closed');
                                if ($isClosed) {
                                    return 'Closed';
                                }
                                if ($record->manual_lifecycle_status === 'flapping') {
                                    return 'Flapping';
                                }
                                if ($record->manual_lifecycle_status === 'close_candidate') {
                                    return 'Ready';
                                }
                                if ($record->manual_lifecycle_status === 'resolved_waiting') {
                                    return 'Waiting for close delay';
                                }
                                if ($record->manual_lifecycle_status === 'cache_stale') {
                                    return 'Cache stale';
                                }
                                if ($record->manual_lifecycle_status === 'identity_missing') {
                                    return 'Missing Zabbix host/trigger identity; lifecycle cannot be evaluated safely.';
                                }
                                if ($record->manual_lifecycle_status === 'active' || $record->zabbix_problem_is_active === true) {
                                    return 'Zabbix problem is still active.';
                                }

                                return 'Unknown';
                            })
                            ->badge()
                            ->color(function (ZabbixTicket $record) {
                                $isClosed = strtolower($record->znuny_ticket_state_type ?? '') === 'closed' || str_contains(strtolower((string) $record->znuny_state_name), 'closed');
                                if ($isClosed) {
                                    return 'gray';
                                }
                                if ($record->manual_lifecycle_status === 'flapping') {
                                    return 'danger';
                                }
                                if ($record->manual_lifecycle_status === 'close_candidate') {
                                    return 'success';
                                }
                                if ($record->manual_lifecycle_status === 'resolved_waiting') {
                                    return 'info';
                                }
                                if ($record->manual_lifecycle_status === 'cache_stale') {
                                    return 'warning';
                                }
                                if ($record->manual_lifecycle_status === 'active' || $record->zabbix_problem_is_active === true) {
                                    return 'danger';
                                }

                                return 'gray';
                            })
                            ->tooltip(function (ZabbixTicket $record) {
                                if ($record->manual_lifecycle_status === 'identity_missing') {
                                    return 'Missing Zabbix host/trigger identity; lifecycle cannot be evaluated safely.';
                                }

                                return null;
                            })
                            ->inlineLabel(),
                    ])->columns(1),

                Section::make('Lifecycle Timing')
                    ->schema([
                        TextEntry::make('zabbix_problem_resolved_at')
                            ->label(self::formatLabel('Problem Resolved At'))
                            ->state(fn (ZabbixTicket $record) => app(DateTimeDisplayService::class)->formatDateTimeWithTimezone($record->zabbix_problem_resolved_at))
                            ->visible(fn (ZabbixTicket $record) => ! empty($record->zabbix_problem_resolved_at))
                            ->inlineLabel(),
                        TextEntry::make('manual_close_eligible_at')
                            ->label(self::formatLabel('Auto-Close Eligible At'))
                            ->state(fn (ZabbixTicket $record) => app(DateTimeDisplayService::class)->formatDateTimeWithTimezone($record->manual_close_eligible_at))
                            ->visible(fn (ZabbixTicket $record) => ! empty($record->manual_close_eligible_at))
                            ->inlineLabel(),
                        TextEntry::make('znuny_ticket_closed_at')
                            ->label(self::formatLabel('Closed At'))
                            ->state(fn (ZabbixTicket $record) => app(DateTimeDisplayService::class)->formatDateTimeWithTimezone($record->znuny_ticket_closed_at))
                            ->visible(fn (ZabbixTicket $record) => ! empty($record->znuny_ticket_closed_at))
                            ->inlineLabel(),
                    ])
                    ->visible(fn (ZabbixTicket $record) => ! empty($record->zabbix_problem_resolved_at) || ! empty($record->manual_close_eligible_at) || ! empty($record->znuny_ticket_closed_at))
                    ->columns(1),

                Section::make('Zabbix')
                    ->schema([
                        TextEntry::make('zabbix_host_name')->label(self::formatLabel('Host'))->inlineLabel()->placeholder('-'),
                        TextEntry::make('zabbix_problem_name')->label(self::formatLabel('Problem'))->inlineLabel()->placeholder('-'),
                    ])->columns(1),

                Section::make('Znuny Snapshot')
                    ->schema([
                        TextEntry::make('znuny_queue_name')->label(self::formatLabel('Queue'))->inlineLabel()->placeholder('-'),
                        TextEntry::make('znuny_owner_name')->label(self::formatLabel('Owner'))->inlineLabel()->placeholder('-'),
                        TextEntry::make('znuny_priority')->label(self::formatLabel('Priority'))->inlineLabel()->placeholder('-'),
                        TextEntry::make('znuny_state_name')->label(self::formatLabel('State'))->inlineLabel()->placeholder('-'),
                    ])->columns(1),

                Section::make('Sync')
                    ->schema([
                        TextEntry::make('znuny_ticket_last_checked_at')
                            ->label(self::formatLabel('Last Checked'))
                            ->state(fn (ZabbixTicket $record) => app(DateTimeDisplayService::class)->formatDateTimeWithTimezone($record->znuny_ticket_last_checked_at))
                            ->inlineLabel()->placeholder('-'),
                        TextEntry::make('znuny_ticket_last_synced_at')
                            ->label(self::formatLabel('Last Synced'))
                            ->state(fn (ZabbixTicket $record) => app(DateTimeDisplayService::class)->formatDateTimeWithTimezone($record->znuny_ticket_last_synced_at))
                            ->inlineLabel()->placeholder('-'),
                        TextEntry::make('znuny_ticket_sync_error')->label(self::formatLabel('Sync Error'))
                            ->color('danger')
                            ->inlineLabel()
                            ->placeholder('-'),
                    ])->columns(1),
            ]);
    }
}
