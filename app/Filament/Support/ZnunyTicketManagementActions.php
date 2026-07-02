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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

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

    public static function takeOrReleaseTicketAction(string $name = 'take_or_release_ticket'): Action
    {
        return Action::make($name)
            ->label(function (array $arguments, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return $payload->lock === 'lock' ? 'Release' : 'Take';
            })
            ->icon(function (array $arguments, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return $payload->lock === 'lock' ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed';
            })
            ->color(function (array $arguments, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return $payload->lock === 'lock' ? 'gray' : 'primary';
            })
            ->tooltip(function (array $arguments, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return $payload->lock === 'lock' ? 'Unlock this ticket' : 'Lock this ticket for work';
            })
            ->visible(function (array $arguments, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return (bool) $payload->znuny_ticket_id
                    && $payload->is_open
                    && in_array($payload->lock, ['lock', 'unlock'], true);
            })
            ->action(function (array $arguments, Action $action, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);
                if (! $payload->znuny_ticket_id) {
                    Notification::make()->title('Ticket ID missing')->danger()->send();
                    $action->halt();

                    return;
                }

                $client = app(ZnunyClient::class);
                $isLocked = $payload->lock === 'lock';

                try {
                    $response = $isLocked
                        ? $client->unlockTicket($payload->znuny_ticket_id)
                        : $client->lockTicket($payload->znuny_ticket_id);

                    if (! $response['success']) {
                        $messages = $response['errors'] ?? [];

                        if (empty($messages)) {
                            $messages = $response['warnings'] ?? [];
                        }

                        if (empty($messages)) {
                            $messages = ['Unknown error'];
                        }

                        Notification::make()
                            ->title($isLocked ? 'Release Failed' : 'Take Failed')
                            ->body(implode(', ', $messages))
                            ->danger()
                            ->send();
                        $action->halt();

                        return;
                    }

                    // Refresh workspace cache for this ticket via service
                    $refreshService = app(ZnunyTicketWorkspaceTicketRefreshService::class);
                    $refreshService->refreshTicket($payload->znuny_ticket_id);

                    Notification::make()
                        ->title($isLocked ? 'Ticket Released' : 'Ticket Taken')
                        ->success()
                        ->send();

                    $action->cancelParentActions();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title($isLocked ? 'Release Failed' : 'Take Failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                    $action->halt();
                }
            });
    }

    public static function changeAssignmentAction(string $name = 'change_assignment'): Action
    {
        return Action::make($name)
            ->label('Change')
            ->icon('heroicon-o-users')
            ->color('gray')
            ->modalHeading('Change Assignment')
            ->form(function (array $arguments, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return [
                    TextInput::make('current_queue')
                        ->label('Current Queue')
                        ->default($payload->znuny_queue_name)
                        ->disabled(),
                    Select::make('target_queue')
                        ->label('Target Queue')
                        ->default($payload->znuny_queue_name)
                        ->required()
                        ->options(function ($get) {
                            $client = app(ZnunyClient::class);
                            $owner = $get('target_owner');
                            if ($owner) {
                                $agents = $client->getAgents();
                                $agentId = collect($agents)->firstWhere('login', $owner)['id'] ?? null;
                                if ($agentId) {
                                    return collect($client->getAgentAssignableQueues($agentId))->pluck('label', 'name')->toArray();
                                }
                            }

                            return collect($client->getQueues())->pluck('label', 'name')->toArray();
                        })
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function ($set, $get, ?string $state) {
                            $owner = $get('target_owner');
                            if ($owner && $state) {
                                $client = app(ZnunyClient::class);
                                $queue = $client->getQueueByName($state);
                                if (! empty($queue['id'])) {
                                    $agents = $client->getQueueAssignableAgents($queue['id']);
                                    $logins = collect($agents)->pluck('login')->toArray();
                                    if (! in_array($owner, $logins)) {
                                        $set('target_owner', null);
                                    }
                                }
                            }
                        }),
                    TextInput::make('current_owner')
                        ->label('Current Owner')
                        ->default($payload->znuny_owner_name)
                        ->disabled(),
                    Select::make('target_owner')
                        ->label('Target Owner')
                        ->default($payload->znuny_owner_name)
                        ->required()
                        ->options(function ($get) {
                            $client = app(ZnunyClient::class);
                            $queueName = $get('target_queue');
                            if ($queueName) {
                                $queue = $client->getQueueByName($queueName);
                                if (! empty($queue['id'])) {
                                    return collect($client->getQueueAssignableAgents($queue['id']))->pluck('label', 'login')->toArray();
                                }
                            }

                            return collect($client->getAgents())->pluck('label', 'login')->toArray();
                        })
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function ($set, $get, ?string $state) {
                            $queueName = $get('target_queue');
                            if ($queueName && $state) {
                                $client = app(ZnunyClient::class);
                                $agents = $client->getAgents();
                                $agentId = collect($agents)->firstWhere('login', $state)['id'] ?? null;
                                if ($agentId) {
                                    $queues = $client->getAgentAssignableQueues($agentId);
                                    $names = collect($queues)->pluck('name')->toArray();
                                    if (! in_array($queueName, $names)) {
                                        $set('target_queue', null);
                                    }
                                }
                            }
                        }),
                    TextInput::make('current_customer')
                        ->label('Current Customer')
                        ->default($payload->customer_user)
                        ->disabled(),
                    Select::make('target_customer')
                        ->label('Target Customer')
                        ->default($payload->customer_user)
                        ->searchable()
                        ->getSearchResultsUsing(function (string $search) {
                            $client = app(ZnunyClient::class);

                            return collect($client->searchCustomerUsers($search, 20))->pluck('label', 'login')->toArray();
                        })
                        ->getOptionLabelUsing(function ($value) {
                            $client = app(ZnunyClient::class);
                            $user = $client->getCustomerUser($value);
                            if (! empty($user['found']) && ! empty($user['label'])) {
                                return $user['label'];
                            }

                            return $value;
                        }),
                    Textarea::make('note')
                        ->label('Note')
                        ->helperText('Optional. If filled, this text will be added as an internal note after the assignment change.'),
                ];
            })
            ->visible(function (array $arguments, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return $payload->znuny_ticket_id && $payload->is_open;
            })
            ->action(function (array $arguments, array $data, Action $action, $record = null) {
                $payloadInfo = TicketDetailsPayload::fromRecord($record, $arguments);
                if (! $payloadInfo->znuny_ticket_id) {
                    Notification::make()->title('Ticket ID missing')->danger()->send();
                    $action->halt();

                    return;
                }

                $client = app(ZnunyClient::class);

                $requestPayload = [
                    'TicketID' => $payloadInfo->znuny_ticket_id,
                ];

                $hasChange = false;
                if (! empty($data['target_queue']) && $data['target_queue'] !== $payloadInfo->znuny_queue_name) {
                    $requestPayload['QueueName'] = $data['target_queue'];
                    $hasChange = true;
                }

                if (! empty($data['target_owner']) && $data['target_owner'] !== $payloadInfo->znuny_owner_name) {
                    $requestPayload['OwnerLogin'] = $data['target_owner'];
                    $hasChange = true;
                }

                if (! empty($data['target_customer']) && $data['target_customer'] !== $payloadInfo->customer_user) {
                    $requestPayload['CustomerUserID'] = $data['target_customer'];
                    $hasChange = true;
                }

                if (! $hasChange) {
                    Notification::make()->title('No changes made')->warning()->send();
                    $action->halt();

                    return;
                }

                $operatorNote = trim($data['note'] ?? '');

                if (isset($requestPayload['OwnerLogin'])) {
                    $requestPayload['Note'] = empty($operatorNote) ? 'Assignment changed from integration UI.' : $operatorNote;
                }

                $validation = $client->validateTicketMoveAssign($requestPayload);

                if (empty($validation['Valid']) || (int) $validation['Valid'] !== 1) {
                    $messages = $validation['Errors'] ?? [];
                    if (empty($messages)) {
                        $messages = $validation['Warnings'] ?? [];
                    }
                    if (empty($messages) && ! empty($validation['RequiredNote'])) {
                        $messages[] = 'Note is required for this assignment change.';
                    }
                    if (empty($messages)) {
                        $messages = ['Validation failed'];
                    }

                    Notification::make()
                        ->title('Validation Failed')
                        ->body(implode(', ', $messages))
                        ->danger()
                        ->send();
                    $action->halt();

                    return;
                }

                $result = $client->moveAssignTicket($requestPayload);

                if (empty($result['Success']) || (int) $result['Success'] !== 1) {
                    $messages = $result['Errors'] ?? [];
                    if (empty($messages)) {
                        $messages = $result['Warnings'] ?? [];
                    }
                    if (empty($messages)) {
                        $messages = ['Execution failed'];
                    }

                    Notification::make()
                        ->title('Update Failed')
                        ->body(implode(', ', $messages))
                        ->danger()
                        ->send();
                    $action->halt();

                    return;
                }

                $operatorNote = trim($data['note'] ?? '');
                if (! empty($operatorNote)) {
                    try {
                        $articleService = app(ZnunyTicketArticleWriteService::class);
                        $articleResult = $articleService->createTicketArticle(
                            (string) $payloadInfo->znuny_ticket_id,
                            'Assignment changed',
                            $operatorNote,
                            false
                        );

                        if (empty($articleResult['success'])) {
                            throw new \Exception('API returned failure for article creation.');
                        }
                    } catch (\Exception $e) {
                        Log::error('Assignment changed, but note could not be added: '.$e->getMessage(), ['ticket_id' => $payloadInfo->znuny_ticket_id, 'exception' => $e]);

                        Notification::make()
                            ->title('Assignment changed, but note could not be added.')
                            ->warning()
                            ->send();
                    }
                }

                try {
                    $refreshService = app(ZnunyTicketWorkspaceTicketRefreshService::class);
                    $refreshService->refreshTicket($payloadInfo->znuny_ticket_id);

                    Notification::make()
                        ->title('Assignment Changed')
                        ->success()
                        ->send();

                    $action->cancelParentActions();
                } catch (\Exception $e) {
                    Log::error('Ticket workspace refresh failed after assignment change: '.$e->getMessage(), ['ticket_id' => $payloadInfo->znuny_ticket_id, 'exception' => $e]);

                    Notification::make()
                        ->title('Assignment changed in Znuny, but local cache refresh failed.')
                        ->body($e->getMessage())
                        ->warning()
                        ->send();
                }
            });
    }
}
