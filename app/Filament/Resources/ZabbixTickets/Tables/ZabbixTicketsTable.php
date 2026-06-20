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
use Illuminate\Support\HtmlString;

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
                    ->wrap()
                    ->description(function (ZabbixTicket $record): ?HtmlString {
                        if (strtolower($record->znuny_ticket_state_type ?? '') === 'closed') {
                            return null;
                        }
                        if ($record->manual_lifecycle_status === 'close_candidate') {
                            return new HtmlString('<span title="Linked Zabbix problem is resolved and close delay has passed." class="inline-flex items-center rounded-md bg-success-50 px-2 py-1 mt-1 text-xs font-medium text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/20">Ready to close</span>');
                        }
                        if ($record->zabbix_problem_is_active === false) {
                            return new HtmlString('<span title="Linked Zabbix problem is no longer active." class="inline-flex items-center rounded-md bg-info-50 px-2 py-1 mt-1 text-xs font-medium text-info-700 ring-1 ring-inset ring-info-600/20 dark:bg-info-400/10 dark:text-info-400 dark:ring-info-400/20">Problem resolved</span>');
                        }

                        return null;
                    }),

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
                ViewAction::make()->slideOver(),
                Action::make('manual_close_ticket')
                    ->label('Close Ticket')
                    ->icon('heroicon-o-check-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Close Znuny Ticket')
                    ->modalDescription('Are you sure you want to manually close this Znuny ticket? This will use the verified /TicketClose workflow.')
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
                    ->action(function (ZabbixTicket $record, array $data) {
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
                Action::make('open_ticket')
                    ->label('Open Ticket')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (?ZabbixTicket $record) => $record ? app(ZnunyClient::class)->ticketUrl($record->znuny_ticket_id) : null)
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([]);
    }
}
