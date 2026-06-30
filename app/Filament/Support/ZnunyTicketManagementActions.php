<?php

namespace App\Filament\Support;

use App\Models\ZabbixTicket;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyLinkedTicketCloseService;
use App\Services\Znuny\ZnunyLinkedTicketReopenService;
use App\Services\Znuny\ZnunyTicketArticleWriteService;
use App\Services\Znuny\ZnunyTicketWorkspaceTicketRefreshService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            ->modalHeading(fn ($record) => $record instanceof ZabbixTicket && $record->manual_lifecycle_status === 'close_candidate' ? 'Close Znuny Ticket' : 'Close Znuny Ticket Anyway?')
            ->modalDescription(fn ($record) => $record instanceof ZabbixTicket && $record->manual_lifecycle_status === 'close_candidate'
                ? 'Close this Znuny ticket? The linked Zabbix problem is resolved and the close delay has passed.'
                : 'Close this Znuny ticket? Use this only if the operator has manually verified that closing is correct.')
            ->form([
                Textarea::make('reason')
                    ->label('Reason / Comment')
                    ->default(fn () => SettingsService::string('linked_ticket_manual_close_default_reason', 'Manual close from UI.'))
                    ->required(),
            ])
            ->visible(function (array $arguments, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return $payload->znuny_ticket_id && $payload->is_open;
            })
            ->action(function (array $arguments, array $data, Action $action, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);
                if (! $payload->znuny_ticket_id) {
                    Notification::make()->title('Ticket ID missing')->danger()->send();
                    $action->halt();

                    return;
                }

                if ($payload->is_zabbix_ticket) {
                    $ticketId = $record instanceof ZabbixTicket ? $record->id : ($arguments['zabbix_ticket_id'] ?? null);
                    $ticket = $record instanceof ZabbixTicket ? $record : ZabbixTicket::find($ticketId);
                    if (! $ticket) {
                        Notification::make()->title('Ticket not found')->danger()->send();
                        $action->halt();

                        return;
                    }

                    $ticket->refresh();
                    $closeService = app(ZnunyLinkedTicketCloseService::class);

                    $result = $closeService->closeTicket(
                        $ticket,
                        'Manual ticket close',
                        'Closed manually from UI.',
                        $data['reason'] ?? SettingsService::string('linked_ticket_manual_close_default_reason', 'Manual close from UI.')
                    );

                    if ($result['success']) {
                        $logContext = [
                            'message' => "Ticket {$ticket->znuny_ticket_number} manually closed via UI.",
                            'znuny_ticket_id' => $ticket->znuny_ticket_id,
                            'znuny_ticket_number' => $ticket->znuny_ticket_number,
                            'host' => $ticket->zabbix_host_name,
                            'problem' => $ticket->zabbix_problem_name,
                            'previous_state' => $ticket->znuny_state_name,
                            'source' => 'linked_tickets_ui',
                        ];
                        if (! empty($result['warning'])) {
                            $logContext['warning'] = $result['warning'];
                        }

                        AuditLogger::log(
                            'znuny.auto_close.success',
                            'zabbix_ticket',
                            $ticket->id,
                            $logContext
                        );
                        if (! empty($result['warning'])) {
                            Notification::make()
                                ->title('Ticket Closed with Warning')
                                ->body($result['warning'])
                                ->warning()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Ticket Closed')
                                ->body('Znuny ticket successfully closed.')
                                ->success()
                                ->send();
                        }

                        $refreshService = app(ZnunyTicketWorkspaceTicketRefreshService::class);
                        $refreshService->refreshTicket($ticket->znuny_ticket_id);
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
                } else {
                    $client = app(ZnunyClient::class);
                    $closePayload = [
                        'Kind' => 'internal_note',
                        'Subject' => 'Manual ticket close',
                        'Body' => 'Closed manually from Workspace UI.',
                        'Reason' => $data['reason'] ?? 'Manual ticket close',
                    ];

                    try {
                        $response = $client->closeTicket($payload->znuny_ticket_id, $closePayload);
                        if (! $response['success']) {
                            Notification::make()
                                ->title('Close Failed')
                                ->body(implode(', ', $response['errors'] ?? ['Unknown error']))
                                ->danger()
                                ->send();
                            $action->halt();

                            return;
                        }

                        // Attempt unlock
                        try {
                            $client->unlockTicket($payload->znuny_ticket_id);
                        } catch (\Throwable $te) {
                            // ignore unlock failures
                        }

                        // refresh workspace cache for this ticket via service
                        $refreshService = app(ZnunyTicketWorkspaceTicketRefreshService::class);
                        $refreshService->refreshTicket($payload->znuny_ticket_id);

                        Notification::make()
                            ->title('Ticket Closed')
                            ->body('Znuny ticket successfully closed.')
                            ->success()
                            ->send();

                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Close Failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        $action->halt();
                    }
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
            ->modalDescription('Reopen this Znuny ticket?')
            ->form([
                Textarea::make('reason')
                    ->label('Reopen Note / Article Body')
                    ->required()
                    ->default(fn () => SettingsService::string('manual_ticket_reopen_note_template', 'Reopening this ticket.')),
            ])
            ->visible(function (array $arguments, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return $payload->znuny_ticket_id && $payload->is_closed;
            })
            ->action(function (array $arguments, array $data, Action $action, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);
                if (! $payload->znuny_ticket_id) {
                    Notification::make()->title('Ticket ID missing')->danger()->send();
                    $action->halt();

                    return;
                }

                if ($payload->is_zabbix_ticket) {
                    $ticketId = $record instanceof ZabbixTicket ? $record->id : ($arguments['zabbix_ticket_id'] ?? null);
                    $ticket = $record instanceof ZabbixTicket ? $record : ZabbixTicket::find($ticketId);
                    if (! $ticket) {
                        Notification::make()->title('Ticket not found')->danger()->send();
                        $action->halt();

                        return;
                    }

                    $reason = $data['reason'] ?? 'Reopening ticket.';
                    $service = app(ZnunyLinkedTicketReopenService::class);
                    $result = $service->reopenTicket($ticket, $reason);

                    if ($result['success']) {
                        $refreshService = app(ZnunyTicketWorkspaceTicketRefreshService::class);
                        $refreshService->refreshTicket($ticket->znuny_ticket_id);

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
                } else {
                    $client = app(ZnunyClient::class);
                    $reopenPayload = [
                        'Kind' => 'internal_note',
                        'Subject' => 'Manual ticket reopen',
                        'Body' => $data['reason'] ?? 'Reopening ticket.',
                        'Reason' => 'Reopening ticket.',
                    ];

                    try {
                        $response = $client->reopenTicket($payload->znuny_ticket_id, $reopenPayload);
                        if (! $response['success']) {
                            Notification::make()
                                ->title('Reopen Failed')
                                ->body(implode(', ', $response['errors'] ?? ['Unknown error']))
                                ->danger()
                                ->send();
                            $action->halt();

                            return;
                        }

                        // refresh workspace cache for this ticket via service
                        $refreshService = app(ZnunyTicketWorkspaceTicketRefreshService::class);
                        $refreshService->refreshTicket($payload->znuny_ticket_id);

                        Notification::make()
                            ->title('Ticket Reopened')
                            ->body('Znuny ticket successfully reopened.')
                            ->success()
                            ->send();

                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Reopen Failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        $action->halt();
                    }
                }
            });
    }

    public static function addNoteOrArticleAction(string $name = 'add_note_or_article'): Action
    {
        return Action::make($name)
            ->label('Add Note / Article')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->modalHeading('Add Note / Article')
            ->modalDescription('Write a message to append to this ticket.')
            ->form([
                TextInput::make('subject')
                    ->label('Subject')
                    ->required()
                    ->maxLength(255)
                    ->default(''),
                Textarea::make('body')
                    ->label('Body')
                    ->required()
                    ->maxLength(65535)
                    ->rows(6),
            ])
            ->visible(function (array $arguments, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return $payload->znuny_ticket_id && $payload->is_open;
            })
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('create_note', arguments: ['visible_for_customer' => false])
                    ->label('Create Note')
                    ->color('gray'),
                $action->makeModalSubmitAction('create_article', arguments: ['visible_for_customer' => true])
                    ->label('Create Article')
                    ->color('primary'),
            ])
            ->modalSubmitAction(false)
            ->action(function (array $arguments, array $data, Action $action, $record = null) {
                $visibleForCustomer = $arguments['visible_for_customer'] ?? false;
                static::executeCreateTicketArticle($arguments, $data, $action, $record, $visibleForCustomer);
            });
    }

    protected static function executeCreateTicketArticle(array $arguments, array $data, Action $action, $record, bool $visibleForCustomer): void
    {
        $payload = TicketDetailsPayload::fromRecord($record, $arguments);

        if (! $payload->znuny_ticket_id) {
            Notification::make()->title('Ticket ID missing')->danger()->send();
            $action->halt();

            return;
        }

        $service = app(ZnunyTicketArticleWriteService::class);
        $result = $service->createTicketArticle(
            $payload->znuny_ticket_id,
            $data['subject'],
            $data['body'],
            $visibleForCustomer
        );

        if ($result['success']) {
            Notification::make()
                ->title($visibleForCustomer ? 'Article Created' : 'Note Created')
                ->body('Your message has been successfully added to the ticket.')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Action Failed')
                ->body(implode(', ', $result['errors'] ?? ['Unknown error']))
                ->danger()
                ->send();

            $action->halt();
        }
    }

    public static function openInZnunyAction(string $name = 'open_ticket'): Action
    {
        return Action::make($name)
            ->label('Open Ticket')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->url(function (array $arguments, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return $payload->znuny_ticket_id ? app(ZnunyClient::class)->ticketUrl($payload->znuny_ticket_id) : null;
            })
            ->visible(function (array $arguments, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return (bool) $payload->znuny_ticket_id;
            })
            ->openUrlInNewTab();
    }
}
