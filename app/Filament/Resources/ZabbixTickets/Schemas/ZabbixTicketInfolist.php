<?php

namespace App\Filament\Resources\ZabbixTickets\Schemas;

use App\Models\ZabbixTicket;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ZabbixTicketInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ticket')
                    ->schema([
                        TextEntry::make('znuny_ticket_number')->label('Ticket Number')->placeholder('-'),
                        TextEntry::make('created_at')->label('Ticket Age')->since()->placeholder('-'),
                        TextEntry::make('resolution_context')
                            ->label('Resolution Context')
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
                            })->columnSpanFull(),
                    ])->columns(2),

                Section::make('Zabbix')
                    ->schema([
                        TextEntry::make('zabbix_host_name')->label('Host')->placeholder('-'),
                        TextEntry::make('zabbix_problem_name')->label('Problem')->columnSpanFull()->placeholder('-'),
                    ])->columns(2),

                Section::make('Znuny Snapshot')
                    ->schema([
                        TextEntry::make('znuny_queue_name')->label('Queue')->placeholder('-'),
                        TextEntry::make('znuny_owner_name')->label('Owner')->placeholder('-'),
                        TextEntry::make('znuny_priority')->label('Priority')->placeholder('-'),
                        TextEntry::make('znuny_state_name')->label('State')->placeholder('-'),
                    ])->columns(2),

                Section::make('Sync')
                    ->schema([
                        TextEntry::make('znuny_ticket_last_checked_at')->label('Last Checked')->dateTime()->placeholder('-'),
                        TextEntry::make('znuny_ticket_last_synced_at')->label('Last Synced')->dateTime()->placeholder('-'),
                        TextEntry::make('znuny_ticket_sync_error')->label('Sync Error')
                            ->color('danger')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }
}
