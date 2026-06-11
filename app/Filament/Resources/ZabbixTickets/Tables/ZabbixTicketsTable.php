<?php

namespace App\Filament\Resources\ZabbixTickets\Tables;

use App\Models\ZabbixTicket;
use App\Services\Znuny\ZnunyClient;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ZabbixTicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('znuny_ticket_number')
                    ->label('Ticket')
                    ->searchable()
                    ->sortable()
                    ->url(fn (ZabbixTicket $record) => app(ZnunyClient::class)->ticketUrl($record->znuny_ticket_id))
                    ->openUrlInNewTab(),

                TextColumn::make('zabbix_host_name')
                    ->label('Host')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('zabbix_problem_name')
                    ->label('Problem')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('zabbix_severity')
                    ->label('Severity')
                    ->sortable()
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

                TextColumn::make('znuny_queue_name')
                    ->label('Queue')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('znuny_owner_name')
                    ->label('Owner')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('znuny_state_name')
                    ->label('State')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
