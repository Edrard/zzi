<?php

namespace App\Filament\Resources\ZabbixTickets\Tables;

use App\Filament\Resources\ZabbixTickets\Actions\ZabbixTicketDetailsAction;
use App\Models\ZabbixTicket;
use App\Services\SettingsService;
use App\Services\Zabbix\ZabbixTicketStatusPresenter;
use App\Services\Znuny\ZnunyClient;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ZabbixTicketsTable
{
    public static function configure(Table $table): Table
    {
        $minutes = SettingsService::int('zabbix_poll_interval_minutes', 1) ?? 1;
        $seconds = max((int) round(($minutes * 60) / 2), 10);

        return $table
            ->poll("{$seconds}s")
            ->recordClasses(fn () => 'text-[13px] [&>td]:px-3 [&>td]:py-2')
            ->columns([
                TextColumn::make('zabbix_host_name')
                    ->label('Host')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('zabbix_problem_name')
                    ->label('Problem')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('znuny_state_name')
                    ->label('State')
                    ->badge()
                    ->color(function (ZabbixTicket $record): string {
                        if (! empty($record->znuny_ticket_sync_error)) {
                            return 'danger';
                        }

                        $stateName = strtolower($record->znuny_state_name ?? '');
                        $stateType = strtolower($record->znuny_ticket_state_type ?? '');

                        if ($stateName === 'open') {
                            return 'warning';
                        }
                        if ($stateName === 'closed successful') {
                            return 'success';
                        }
                        if ($stateName === 'closed unsuccessful') {
                            return 'danger';
                        }
                        if ($stateType === 'closed') {
                            return 'gray';
                        }
                        if ($stateType === 'open') {
                            return 'warning';
                        }

                        return 'gray';
                    })
                    ->icon(fn (ZabbixTicket $record): ?string => ! empty($record->znuny_ticket_sync_error) ? 'heroicon-o-exclamation-triangle' : null)
                    ->tooltip(fn (ZabbixTicket $record): ?string => $record->znuny_ticket_sync_error ?: null)
                    ->formatStateUsing(function (ZabbixTicket $record, ?string $state) {
                        if (! empty($record->znuny_ticket_sync_error)) {
                            return 'Sync Error';
                        }
                        if (empty($state)) {
                            return 'not synced';
                        }

                        return $state;
                    })
                    ->sortable()
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('zabbix_problem_status')
                    ->label('Zabbix')
                    ->badge()
                    ->state(fn (ZabbixTicket $record) => ZabbixTicketStatusPresenter::getLifecyclePresentation($record)['label'])
                    ->color(fn (ZabbixTicket $record) => ZabbixTicketStatusPresenter::getLifecyclePresentation($record)['color'])
                    ->icon(fn (ZabbixTicket $record) => ZabbixTicketStatusPresenter::getLifecyclePresentation($record)['icon'])
                    ->tooltip(fn (ZabbixTicket $record) => ZabbixTicketStatusPresenter::getLifecyclePresentation($record)['tooltip']),

                TextColumn::make('created_at')
                    ->label('Ticket Age')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                ZabbixTicketDetailsAction::make(),
                Action::make('open_ticket')
                    ->label('Open Ticket')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (?ZabbixTicket $record) => $record ? app(ZnunyClient::class)->ticketUrl($record->znuny_ticket_id) : null)
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([]);
    }
}
