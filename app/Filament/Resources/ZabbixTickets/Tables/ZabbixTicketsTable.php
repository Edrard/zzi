<?php

namespace App\Filament\Resources\ZabbixTickets\Tables;

use App\Filament\Resources\ZabbixTickets\Actions\ZabbixTicketDetailsAction;
use App\Filament\Support\ZnunyTicketManagementActions;
use App\Models\ZabbixTicket;
use App\Services\Zabbix\ZabbixTicketStatusPresenter;
use App\Support\Pagination\PaginationSettings;
use App\Support\Polling\UiPollInterval;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ZabbixTicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(app(PaginationSettings::class)->defaultPerPage())
            ->paginationPageOptions(app(PaginationSettings::class)->perPageOptions())
            ->poll(UiPollInterval::getLivewireString())
            ->recordClasses(fn () => 'linked-tickets-compact-table')
            ->recordAction('viewTicket')
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
                ZabbixTicketDetailsAction::make()
                    ->extraAttributes(['class' => 'linked-tickets-hidden-view-action']),
                ZnunyTicketManagementActions::openInZnunyAction('open_ticket')
                    ->size(Size::Small),
            ])
            ->toolbarActions([]);
    }
}
