<?php

namespace App\Filament\Support;

use App\Models\ZabbixTicket;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyLinkedTicketCloseService;
use App\Services\Znuny\ZnunyLinkedTicketReopenService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

class ZnunyTicketManagementActions
{
    public static function closeTicketAction(string $name = 'manual_close_ticket'): Action
    {
        return Action::make($name)
            ->label('Close Ticket')
            ->icon('heroicon-o-check-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (?ZabbixTicket $record) => $record && $record->manual_lifecycle_status === 'close_candidate' ? 'Close Znuny Ticket' : 'Close Znuny Ticket Anyway?')
            ->modalDescription(fn (?ZabbixTicket $record) => $record && $record->manual_lifecycle_status === 'close_candidate'
                ? 'Close this Znuny ticket? The linked Zabbix problem is resolved and the close delay has passed.'
                : 'Close this Znuny ticket anyway? This ticket is not marked as Ready to close. Use this only if the operator has manually verified that closing is correct.')
            ->form([
                Textarea::make('reason')
                    ->label('Reason / Comment')
                    ->default(fn () => SettingsService::string('linked_ticket_manual_close_default_reason', 'Manual close from Linked Tickets UI.'))
                    ->required(),
            ])
            ->visible(function (?ZabbixTicket $record) {
                if (! $record || empty($record->znuny_ticket_id)) {
                    return false;
                }

                return ! $record->isClosedInZnuny();
            })
            ->action(function (array $arguments, array $data, Action $action, ?ZabbixTicket $record = null) {
                $ticketId = $record ? $record->id : ($arguments['ticket_id'] ?? null);
                if (! $ticketId) {
                    Notification::make()->title('Ticket ID missing')->danger()->send();

                    return;
                }

                $ticket = $record ?? ZabbixTicket::find($ticketId);
                if (! $ticket) {
                    Notification::make()->title('Ticket not found')->danger()->send();

                    return;
                }

                $ticket->refresh();
                $closeService = app(ZnunyLinkedTicketCloseService::class);

                $result = $closeService->closeTicket(
                    $ticket,
                    'Manual ticket close',
                    'Closed manually from Linked Tickets UI.',
                    $data['reason'] ?? SettingsService::string('linked_ticket_manual_close_default_reason', 'Manual close from Linked Tickets UI.')
                );

                if ($result['success']) {
                    AuditLogger::log(
                        'znuny.auto_close.success',
                        'zabbix_ticket',
                        $ticket->id,
                        [
                            'message' => "Ticket {$ticket->znuny_ticket_number} manually closed via UI.",
                            'znuny_ticket_id' => $ticket->znuny_ticket_id,
                            'znuny_ticket_number' => $ticket->znuny_ticket_number,
                            'host' => $ticket->zabbix_host_name,
                            'problem' => $ticket->zabbix_problem_name,
                            'previous_state' => $ticket->znuny_state_name,
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
                        $ticket->id,
                        [
                            'message' => "Manual UI close failed for ticket {$ticket->znuny_ticket_number}: ".($result['reason'] ?? 'Unknown error'),
                            'znuny_ticket_id' => $ticket->znuny_ticket_id,
                            'znuny_ticket_number' => $ticket->znuny_ticket_number,
                            'host' => $ticket->zabbix_host_name,
                            'problem' => $ticket->zabbix_problem_name,
                            'previous_state' => $ticket->znuny_state_name,
                            'source' => 'linked_tickets_ui',
                            'error' => $result['reason'] ?? 'Unknown error',
                        ]
                    );
                    Notification::make()
                        ->title('Close Failed')
                        ->body($result['reason'] ?? 'Failed to close ticket.')
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }

    public static function reopenTicketAction(string $name = 'reopen_ticket'): Action
    {
        return Action::make($name)
            ->label('Reopen Ticket')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Reopen Znuny Ticket')
            ->modalDescription('Reopen the linked Znuny ticket because the Zabbix problem is active again?')
            ->form([
                Textarea::make('reason')
                    ->label('Reopen Note / Article Body')
                    ->required()
                    ->default(fn () => SettingsService::string('manual_ticket_reopen_note_template', 'Reopening this ticket because the linked Zabbix problem became active again within the configured reopen window.')),
            ])
            ->visible(fn (?ZabbixTicket $record) => $record && $record->isReopenCandidate())
            ->action(function (array $arguments, array $data, Action $action, ?ZabbixTicket $record = null) {
                $ticketId = $record ? $record->id : ($arguments['ticket_id'] ?? null);
                if (! $ticketId) {
                    Notification::make()->title('Ticket ID missing')->danger()->send();
                    $action->halt();
                }

                $ticket = $record ?? ZabbixTicket::find($ticketId);
                if (! $ticket) {
                    Notification::make()->title('Ticket not found')->danger()->send();
                    $action->halt();
                }

                $reason = $data['reason'] ?? 'Reopening ticket.';
                $service = app(ZnunyLinkedTicketReopenService::class);
                $result = $service->reopenTicket($ticket, $reason);

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

                    $action->halt();
                }
            });
    }

    public static function openInZnunyAction(string $name = 'open_ticket'): Action
    {
        return Action::make($name)
            ->label('Open Ticket')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->url(fn (?ZabbixTicket $record) => $record ? app(ZnunyClient::class)->ticketUrl($record->znuny_ticket_id) : null)
            ->openUrlInNewTab();
    }
}
