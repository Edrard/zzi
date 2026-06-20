<?php

namespace App\Filament\Resources\ZabbixTickets\Tables;

use App\Models\ZabbixTicket;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyLinkedTicketCloseService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
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
                    ->icon(function (ZabbixTicket $record) {
                        $isClosed = strtolower($record->znuny_ticket_state_type ?? '') === 'closed' || str_contains(strtolower((string) $record->znuny_state_name), 'closed');
                        if ($isClosed) {
                            return 'heroicon-o-check-circle';
                        }
                        if ($record->manual_lifecycle_status === 'flapping') {
                            return 'heroicon-o-exclamation-triangle';
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
                ViewAction::make()
                    ->slideOver()
                    ->mutateRecordDataUsing(function (ZabbixTicket $record, array $data) {
                        $record->refresh();

                        return $data;
                    })
                    ->extraModalFooterActions(fn (Action $action): array => [
                        Action::make('manual_close_ticket')
                            ->label('Close Ticket')
                            ->icon('heroicon-o-check-circle')
                            ->color('danger')
                            ->requiresConfirmation()
                            ->modalHeading(fn (ZabbixTicket $record) => $record->manual_lifecycle_status === 'close_candidate' ? 'Close Znuny Ticket' : 'Close Znuny Ticket Anyway?')
                            ->modalDescription(fn (ZabbixTicket $record) => $record->manual_lifecycle_status === 'close_candidate'
                                ? 'Close this Znuny ticket? The linked Zabbix problem is resolved and the close delay has passed.'
                                : 'Close this Znuny ticket anyway? This ticket is not marked as Ready to close. Use this only if the operator has manually verified that closing is correct.')
                            ->form([
                                Textarea::make('reason')
                                    ->label('Reason / Comment')
                                    ->default('Manual close from Linked Tickets UI.')
                                    ->required(),
                            ])
                            ->visible(function (ZabbixTicket $record) {
                                if (empty($record->znuny_ticket_id)) {
                                    return false;
                                }
                                $stateName = strtolower($record->znuny_state_name ?? '');
                                $stateType = strtolower($record->znuny_ticket_state_type ?? '');
                                if ($stateType === 'closed' || str_contains($stateName, 'closed')) {
                                    return false;
                                }

                                return true;
                            })
                            ->action(function (ZabbixTicket $record, array $data) use ($action) {
                                $record->refresh();
                                $closeService = app(ZnunyLinkedTicketCloseService::class);

                                $result = $closeService->closeTicket(
                                    $record,
                                    'Manual ticket close',
                                    'Closed manually from Linked Tickets UI.',
                                    $data['reason'] ?? 'Manual close from Linked Tickets UI.'
                                );

                                if ($result['success']) {
                                    AuditLogger::log(
                                        'znuny.auto_close.success',
                                        'zabbix_ticket',
                                        $record->id,
                                        [
                                            'message' => "Ticket {$record->znuny_ticket_number} manually closed via UI.",
                                            'znuny_ticket_id' => $record->znuny_ticket_id,
                                            'znuny_ticket_number' => $record->znuny_ticket_number,
                                            'host' => $record->zabbix_host_name,
                                            'problem' => $record->zabbix_problem_name,
                                            'previous_state' => $record->znuny_state_name,
                                            'source' => 'linked_tickets_ui',
                                        ]
                                    );
                                    Notification::make()
                                        ->title('Ticket Closed')
                                        ->body('Znuny ticket successfully closed.')
                                        ->success()
                                        ->send();

                                    $action->cancel();
                                } else {
                                    AuditLogger::log(
                                        'znuny.auto_close.failed',
                                        'zabbix_ticket',
                                        $record->id,
                                        [
                                            'message' => "Manual UI close failed for ticket {$record->znuny_ticket_number}: ".($result['reason'] ?? 'Unknown error'),
                                            'znuny_ticket_id' => $record->znuny_ticket_id,
                                            'znuny_ticket_number' => $record->znuny_ticket_number,
                                            'host' => $record->zabbix_host_name,
                                            'problem' => $record->zabbix_problem_name,
                                            'previous_state' => $record->znuny_state_name,
                                            'source' => 'linked_tickets_ui',
                                            'error' => $result['reason'] ?? 'Unknown error',
                                        ]
                                    );
                                    Notification::make()
                                        ->title('Close Failed')
                                        ->body($result['reason'] ?? 'Failed to close ticket.')
                                        ->danger()
                                        ->send();
                                }
                            }),
                    ]),
                Action::make('open_ticket')
                    ->label('Open Ticket')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (?ZabbixTicket $record) => $record ? app(ZnunyClient::class)->ticketUrl($record->znuny_ticket_id) : null)
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([]);
    }
}
