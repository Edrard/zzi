<?php

namespace App\Filament\Resources\ZabbixTickets\Schemas;

use App\Models\ZabbixTicket;
use App\Services\Znuny\ZnunyClient;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ZabbixTicketInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Zabbix')
                    ->schema([
                        TextEntry::make('zabbix_event_id')->label('Event ID'),
                        TextEntry::make('zabbix_trigger_id')->label('Trigger ID')->placeholder('-'),
                        TextEntry::make('zabbix_host_id')->label('Host ID')->placeholder('-'),
                        TextEntry::make('zabbix_host_name')->label('Host name'),
                        TextEntry::make('zabbix_problem_name')->label('Problem')->columnSpanFull(),
                        TextEntry::make('zabbix_severity')
                            ->label('Severity')
                            ->badge()
                            ->formatStateUsing(fn (?int $state) => match ($state) {
                                0 => 'Not classified',
                                1 => 'Information',
                                2 => 'Warning',
                                3 => 'Average',
                                4 => 'High',
                                5 => 'Disaster',
                                default => 'Unknown',
                            })
                            ->color(fn (?int $state): string => match ($state) {
                                0 => 'gray',
                                1 => 'info',
                                2 => 'warning',
                                3 => 'warning',
                                4 => 'danger',
                                5 => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('zabbix_started_at')->label('Started at')->dateTime()->placeholder('-'),
                    ])->columns(2),

                Section::make('Znuny')
                    ->schema([
                        TextEntry::make('znuny_ticket_number')->label('Ticket number'),
                        TextEntry::make('znuny_ticket_id')->label('Ticket ID'),
                        TextEntry::make('znuny_queue_name')->label('Queue')->placeholder('-'),
                        TextEntry::make('znuny_owner_name')->label('Owner')->placeholder('-'),
                        TextEntry::make('znuny_state_name')->label('State')->placeholder('-'),
                        TextEntry::make('open_in_znuny')
                            ->label('')
                            ->formatStateUsing(fn () => 'Open in Znuny')
                            ->url(fn (ZabbixTicket $record) => app(ZnunyClient::class)->ticketUrl($record->znuny_ticket_id))
                            ->openUrlInNewTab()
                            ->color('primary'),
                    ])->columns(2),

                Section::make('Metadata')
                    ->schema([
                        TextEntry::make('creator.name')->label('Created by')->placeholder('-'),
                        TextEntry::make('created_at')->label('Created at')->dateTime(),
                        TextEntry::make('updated_at')->label('Updated at')->dateTime(),
                    ])->columns(3),
            ]);
    }
}
