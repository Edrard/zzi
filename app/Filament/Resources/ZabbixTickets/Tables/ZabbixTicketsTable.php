<?php

namespace App\Filament\Resources\ZabbixTickets\Tables;

use App\Filament\Resources\ZabbixTickets\Actions\ZabbixTicketDetailsAction;
use App\Models\ZabbixTicket;
use App\Services\SettingsService;
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
                    ->state(function (ZabbixTicket $record) {
                        if ($record->manual_lifecycle_status === 'flapping') {
                            return 'Flapping';
                        }
                        if ($record->manual_lifecycle_status === 'reopen_candidate') {
                            return 'Manual reopen candidate';
                        }
                        if ($record->manual_lifecycle_status === 'reopened' || $record->manual_reopened_at !== null) {
                            return 'Reopened';
                        }
                        $isClosed = strtolower($record->znuny_ticket_state_type ?? '') === 'closed' || str_contains(strtolower((string) $record->znuny_state_name), 'closed');
                        if ($isClosed) {
                            return 'Closed';
                        }
                        if ($record->manual_lifecycle_status === 'close_candidate') {
                            return 'Ready';
                        }
                        if ($record->manual_lifecycle_status === 'resolved_waiting') {
                            return 'Waiting';
                        }
                        if ($record->manual_lifecycle_status === 'cache_stale') {
                            return 'Cache stale';
                        }
                        if ($record->manual_lifecycle_status === 'active' || $record->zabbix_problem_is_active === true) {
                            return 'Active';
                        }

                        return 'Unknown';
                    })
                    ->color(function (ZabbixTicket $record) {
                        if ($record->manual_lifecycle_status === 'flapping') {
                            return 'danger';
                        }
                        if ($record->manual_lifecycle_status === 'reopen_candidate') {
                            return 'warning';
                        }
                        if ($record->manual_lifecycle_status === 'reopened' || $record->manual_reopened_at !== null) {
                            return 'info';
                        }
                        $isClosed = strtolower($record->znuny_ticket_state_type ?? '') === 'closed' || str_contains(strtolower((string) $record->znuny_state_name), 'closed');
                        if ($isClosed) {
                            return 'gray';
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
                    ->icon(function (ZabbixTicket $record) {
                        if ($record->manual_lifecycle_status === 'flapping') {
                            return 'heroicon-o-exclamation-triangle';
                        }
                        if ($record->manual_lifecycle_status === 'reopen_candidate') {
                            return 'heroicon-o-arrow-path';
                        }
                        if ($record->manual_lifecycle_status === 'reopened' || $record->manual_reopened_at !== null) {
                            return 'heroicon-o-arrow-uturn-left';
                        }
                        $isClosed = strtolower($record->znuny_ticket_state_type ?? '') === 'closed' || str_contains(strtolower((string) $record->znuny_state_name), 'closed');
                        if ($isClosed) {
                            return 'heroicon-o-check-circle';
                        }
                        if ($record->manual_lifecycle_status === 'close_candidate') {
                            return 'heroicon-o-check-circle';
                        }
                        if ($record->manual_lifecycle_status === 'resolved_waiting') {
                            return 'heroicon-o-check';
                        }
                        if ($record->manual_lifecycle_status === 'cache_stale') {
                            return 'heroicon-o-clock';
                        }
                        if ($record->manual_lifecycle_status === 'active' || $record->zabbix_problem_is_active === true) {
                            return 'heroicon-o-exclamation-circle';
                        }

                        return 'heroicon-o-question-mark-circle';
                    })
                    ->tooltip(function (ZabbixTicket $record) {
                        if ($record->manual_lifecycle_status === 'flapping') {
                            return 'Flapping problem detected';
                        }
                        if ($record->manual_lifecycle_status === 'reopen_candidate') {
                            return 'Znuny ticket is closed, but the linked Zabbix problem is active again within the reopen window. Choose Reopen or Create Ticket manually.';
                        }
                        if ($record->manual_lifecycle_status === 'reopened' || $record->manual_reopened_at !== null) {
                            return 'Manually reopened ticket';
                        }
                        $isClosed = strtolower($record->znuny_ticket_state_type ?? '') === 'closed' || str_contains(strtolower((string) $record->znuny_state_name), 'closed');
                        if ($isClosed) {
                            return null;
                        }
                        if ($record->manual_lifecycle_status === 'flapping') {
                            return 'Problem became active again after being resolved.';
                        }
                        if ($record->manual_lifecycle_status === 'close_candidate') {
                            return 'Linked Zabbix problem is resolved and close delay has passed.';
                        }
                        if ($record->manual_lifecycle_status === 'resolved_waiting') {
                            return 'Linked Zabbix problem is resolved, waiting for close delay.';
                        }
                        if ($record->manual_lifecycle_status === 'cache_stale') {
                            return 'Zabbix problem cache may be stale. Waiting for sync.';
                        }
                        if ($record->manual_lifecycle_status === 'identity_missing') {
                            return 'Missing Zabbix host/trigger identity; lifecycle cannot be evaluated safely.';
                        }
                        if ($record->manual_lifecycle_status === 'active' || $record->zabbix_problem_is_active === true) {
                            return 'Linked Zabbix problem is still active.';
                        }

                        return 'Lifecycle state has not been evaluated yet.';
                    }),

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
