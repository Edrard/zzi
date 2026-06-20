<?php

namespace App\Filament\Resources\ZabbixTickets\Schemas;

use Filament\Infolists\Components\IconEntry;
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
                    ])->columns(2),

                Section::make('Zabbix')
                    ->schema([
                        TextEntry::make('zabbix_host_name')->label('Host')->placeholder('-'),
                        TextEntry::make('zabbix_problem_name')->label('Problem')->columnSpanFull()->placeholder('-'),
                    ])->columns(2),

                Section::make('Lifecycle')
                    ->schema([
                        TextEntry::make('manual_lifecycle_status')->label('Status')->badge()->placeholder('-'),
                        IconEntry::make('zabbix_problem_is_active')->label('Problem Active')->boolean()->placeholder('-'),
                        TextEntry::make('zabbix_problem_resolved_at')->label('Resolved Since')->dateTime()->placeholder('-'),
                        TextEntry::make('manual_close_eligible_at')->label('Close Eligible At')->dateTime()->placeholder('-'),
                        TextEntry::make('manual_flap_count')->label('Flap Count')->placeholder('-'),
                        TextEntry::make('manual_flapping_detected_at')->label('Flapping Since')->dateTime()->placeholder('-'),
                    ])->columns(2)
                    ->visible(fn ($record) => $record->creation_source === 'manual'),

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
