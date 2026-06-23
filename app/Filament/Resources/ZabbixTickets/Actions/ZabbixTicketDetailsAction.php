<?php

namespace App\Filament\Resources\ZabbixTickets\Actions;

use App\Filament\Resources\ZabbixTickets\Schemas\ZabbixTicketInfolist;
use App\Models\ZabbixTicket;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyLinkedTicketCloseService;
use App\Services\Znuny\ZnunyLinkedTicketReopenService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class ZabbixTicketDetailsAction
{
    public static function make(string $name = 'viewTicket'): ViewAction
    {
        return ViewAction::make($name)
            ->slideOver()
            ->modalWidth(Width::FourExtraLarge)
            ->schema(fn (Schema $schema) => ZabbixTicketInfolist::configure($schema))
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
                            ->default(fn () => SettingsService::string('linked_ticket_manual_close_default_reason', 'Manual close from Linked Tickets UI.'))
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
                            $data['reason'] ?? SettingsService::string('linked_ticket_manual_close_default_reason', 'Manual close from Linked Tickets UI.')
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
                Action::make('reopen_ticket')
                    ->label('Reopen')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reopen Znuny Ticket')
                    ->modalDescription('Reopen this closed manual ticket?')
                    ->form([
                        Textarea::make('reason')
                            ->label('Reopen Note / Article Body')
                            ->required()
                            ->default(fn () => SettingsService::string('manual_ticket_reopen_note_template', 'Reopening this ticket because the linked Zabbix problem became active again within the configured reopen window.')),
                    ])
                    ->visible(fn (ZabbixTicket $record) => $record->manual_lifecycle_status === 'reopen_candidate')
                    ->action(function (ZabbixTicket $record, array $data, Action $action) {
                        $reason = $data['reason'] ?? 'Reopening ticket.';
                        $service = app(ZnunyLinkedTicketReopenService::class);
                        $result = $service->reopenTicket($record, $reason);

                        if ($result['success']) {
                            Notification::make()
                                ->title('Ticket Reopened')
                                ->body('Znuny ticket successfully reopened.')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Reopen Failed')
                                ->body($result['reason'] ?? 'Failed to reopen ticket.')
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }
}
