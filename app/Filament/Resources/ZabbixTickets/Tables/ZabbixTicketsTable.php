<?php

namespace App\Filament\Resources\ZabbixTickets\Tables;

use App\Filament\Resources\ZabbixTickets\Actions\ZabbixTicketDetailsAction;
use App\Filament\Resources\ZabbixTickets\ZabbixTicketResource;
use App\Models\ZabbixTicket;
use App\Services\Support\DateTimeDisplayService;
use App\Services\Zabbix\ZabbixTicketStatusPresenter;
use App\Support\Pagination\PaginationSettings;
use App\Support\Polling\UiPollInterval;
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
            ->emptyStateHeading(__('zabbix_tickets.table.empty_state.heading'))
            ->emptyStateDescription(fn (Table $table) => $table->getLivewire()->getTableSearch() !== null && $table->getLivewire()->getTableSearch() !== ''
                ? __('zabbix_tickets.table.empty_state.description')
                : null)
            ->columns([
                TextColumn::make('zabbix_host_name')
                    ->label(__('zabbix_tickets.table.columns.host'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('zabbix_problem_name')
                    ->label(__('zabbix_tickets.table.columns.problem'))
                    ->searchable()
                    ->wrap(),

                TextColumn::make('znuny_state_name')
                    ->label(__('zabbix_tickets.table.columns.state'))
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
                            return __('zabbix_tickets.details_modal.placeholders.sync_error');
                        }
                        if (empty($state)) {
                            return __('zabbix_tickets.details_modal.placeholders.not_synced');
                        }

                        return ZabbixTicketResource::translateZnunyState($state);
                    })
                    ->sortable()
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('zabbix_problem_status')
                    ->label(__('zabbix_tickets.table.columns.zabbix'))
                    ->badge()
                    ->state(fn (ZabbixTicket $record) => ZabbixTicketResource::translateZabbixStatus(ZabbixTicketStatusPresenter::getLifecyclePresentation($record))['label'])
                    ->color(fn (ZabbixTicket $record) => ZabbixTicketStatusPresenter::getLifecyclePresentation($record)['color'])
                    ->icon(fn (ZabbixTicket $record) => ZabbixTicketStatusPresenter::getLifecyclePresentation($record)['icon'])
                    ->tooltip(fn (ZabbixTicket $record) => ZabbixTicketResource::translateZabbixStatus(ZabbixTicketStatusPresenter::getLifecyclePresentation($record))['tooltip'] ?? null),

                TextColumn::make('created_at')
                    ->label(__('zabbix_tickets.table.columns.ticket_age'))
                    ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->diffForHumans($state))
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                ZabbixTicketDetailsAction::make()
                    ->extraAttributes(['class' => 'linked-tickets-hidden-view-action']),
            ])
            ->toolbarActions([]);
    }
}
