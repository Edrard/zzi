<?php

namespace App\Filament\Support;

use App\Models\ZabbixTicket;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyAgentService;
use App\Services\Znuny\ZnunyAssignmentDependencyService;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyLinkedTicketCloseService;
use App\Services\Znuny\ZnunyLinkedTicketReopenService;
use App\Services\Znuny\ZnunyTicketArticleWriteService;
use App\Services\Znuny\ZnunyTicketWorkspaceTicketRefreshService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

class ZnunyTicketManagementActions
{
    public static function closeTicketAction(string $name = 'manual_close_ticket'): Action
    {
        return Action::make($name)
            ->label(__('znuny_ticket_workspace.management_actions.close_ticket'))
            ->icon('heroicon-o-check-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn ($record) => $record instanceof ZabbixTicket && $record->manual_lifecycle_status === 'close_candidate' ? __('znuny_ticket_workspace.management_actions.close_ticket_heading_candidate') : __('znuny_ticket_workspace.management_actions.close_ticket_heading_anyway'))
            ->modalDescription(fn ($record) => $record instanceof ZabbixTicket && $record->manual_lifecycle_status === 'close_candidate'
                ? __('znuny_ticket_workspace.management_actions.close_ticket_desc_candidate')
                : __('znuny_ticket_workspace.management_actions.close_ticket_desc_anyway'))
            ->form([
                Textarea::make('reason')
                    ->label(__('znuny_ticket_workspace.management_actions.reason_comment'))
                    ->default(fn () => SettingsService::string('linked_ticket_manual_close_default_reason', 'Manual close from UI.'))
                    ->required(),
            ])
            ->visible(function (array $arguments, $record = null) {
                if (auth()->user()?->canManageZnunyTickets() !== true) {
                    return false;
                }
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return $payload->znuny_ticket_id && $payload->is_open;
            })
            ->action(function (array $arguments, array $data, Action $action, $record = null) {
                abort_unless(auth()->user()?->canManageZnunyTickets(), 403);
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);
                if (! $payload->znuny_ticket_id) {
                    Notification::make()->title(__('znuny_ticket_workspace.management_actions.ticket_id_missing'))->danger()->send();
                    $action->halt();

                    return;
                }

                if ($payload->is_zabbix_ticket) {
                    $ticketId = $record instanceof ZabbixTicket ? $record->id : ($arguments['zabbix_ticket_id'] ?? null);
                    $ticket = $record instanceof ZabbixTicket ? $record : ZabbixTicket::find($ticketId);
                    if (! $ticket) {
                        Notification::make()->title(__('znuny_ticket_workspace.management_actions.ticket_not_found'))->danger()->send();
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
                                ->title(__('znuny_ticket_workspace.management_actions.close_warning'))
                                ->body($result['warning'])
                                ->warning()
                                ->send();
                        } else {
                            Notification::make()
                                ->title(__('znuny_ticket_workspace.management_actions.ticket_closed'))
                                ->body(__('znuny_ticket_workspace.management_actions.close_success_body'))
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
                            ->title(__('znuny_ticket_workspace.management_actions.close_failed'))
                            ->body($result['reason'] ?? __('znuny_ticket_workspace.management_actions.close_failed_body_fallback'))
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
                                ->title(__('znuny_ticket_workspace.management_actions.close_failed'))
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
                            ->title(__('znuny_ticket_workspace.management_actions.ticket_closed'))
                            ->body(__('znuny_ticket_workspace.management_actions.close_success_body'))
                            ->success()
                            ->send();

                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title(__('znuny_ticket_workspace.management_actions.close_failed'))
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
            ->label(__('znuny_ticket_workspace.management_actions.reopen_ticket'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('znuny_ticket_workspace.management_actions.reopen_heading'))
            ->modalDescription(__('znuny_ticket_workspace.management_actions.reopen_desc'))
            ->form([
                Textarea::make('reason')
                    ->label(__('znuny_ticket_workspace.management_actions.reopen_note_label'))
                    ->required()
                    ->default(fn () => SettingsService::string('manual_ticket_reopen_note_template', 'Reopening this ticket.')),
            ])
            ->visible(function (array $arguments, $record = null) {
                if (auth()->user()?->canManageZnunyTickets() !== true) {
                    return false;
                }
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return $payload->znuny_ticket_id && $payload->is_closed;
            })
            ->action(function (array $arguments, array $data, Action $action, $record = null) {
                abort_unless(auth()->user()?->canManageZnunyTickets(), 403);
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);
                if (! $payload->znuny_ticket_id) {
                    Notification::make()->title(__('znuny_ticket_workspace.management_actions.ticket_id_missing'))->danger()->send();
                    $action->halt();

                    return;
                }

                if ($payload->is_zabbix_ticket) {
                    $ticketId = $record instanceof ZabbixTicket ? $record->id : ($arguments['zabbix_ticket_id'] ?? null);
                    $ticket = $record instanceof ZabbixTicket ? $record : ZabbixTicket::find($ticketId);
                    if (! $ticket) {
                        Notification::make()->title(__('znuny_ticket_workspace.management_actions.ticket_not_found'))->danger()->send();
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
                            ->title(__('znuny_ticket_workspace.management_actions.ticket_reopened'))
                            ->body(__('znuny_ticket_workspace.management_actions.reopen_success_body'))
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title(__('znuny_ticket_workspace.management_actions.reopen_failed'))
                            ->body($result['reason'] ?? __('znuny_ticket_workspace.management_actions.reopen_failed_body_fallback'))
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
                                ->title(__('znuny_ticket_workspace.management_actions.reopen_failed'))
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
                            ->title(__('znuny_ticket_workspace.management_actions.ticket_reopened'))
                            ->body(__('znuny_ticket_workspace.management_actions.reopen_success_body'))
                            ->success()
                            ->send();

                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title(__('znuny_ticket_workspace.management_actions.reopen_failed'))
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
            ->label(__('znuny_ticket_workspace.management_actions.add_note_article'))
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->modalHeading(__('znuny_ticket_workspace.management_actions.add_note_heading'))
            ->modalDescription(__('znuny_ticket_workspace.management_actions.add_note_desc'))
            ->form([
                TextInput::make('subject')
                    ->label(__('znuny_ticket_workspace.management_actions.subject'))
                    ->required()
                    ->maxLength(255)
                    ->default(''),
                Textarea::make('body')
                    ->label(__('znuny_ticket_workspace.management_actions.body'))
                    ->required()
                    ->maxLength(65535)
                    ->rows(6),
            ])
            ->visible(function (array $arguments, $record = null) {
                if (auth()->user()?->canManageZnunyTickets() !== true) {
                    return false;
                }
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return $payload->znuny_ticket_id && $payload->is_open;
            })
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('create_note', arguments: ['visible_for_customer' => false])
                    ->label(__('znuny_ticket_workspace.management_actions.create_note'))
                    ->color('gray'),
                $action->makeModalSubmitAction('create_article', arguments: ['visible_for_customer' => true])
                    ->label(__('znuny_ticket_workspace.management_actions.create_article'))
                    ->color('primary'),
            ])
            ->modalSubmitAction(false)
            ->action(function (array $arguments, array $data, Action $action, $record = null) {
                abort_unless(auth()->user()?->canManageZnunyTickets(), 403);
                $visibleForCustomer = $arguments['visible_for_customer'] ?? false;
                static::executeCreateTicketArticle($arguments, $data, $action, $record, $visibleForCustomer);
            });
    }

    protected static function executeCreateTicketArticle(array $arguments, array $data, Action $action, $record, bool $visibleForCustomer): void
    {
        abort_unless(auth()->user()?->canManageZnunyTickets(), 403);
        $payload = TicketDetailsPayload::fromRecord($record, $arguments);

        if (! $payload->znuny_ticket_id) {
            Notification::make()->title(__('znuny_ticket_workspace.management_actions.ticket_id_missing'))->danger()->send();
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
                ->title($visibleForCustomer ? __('znuny_ticket_workspace.management_actions.article_created') : __('znuny_ticket_workspace.management_actions.note_created'))
                ->body(__('znuny_ticket_workspace.management_actions.add_note_success_body'))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(__('znuny_ticket_workspace.management_actions.action_failed'))
                ->body(implode(', ', $result['errors'] ?? ['Unknown error']))
                ->danger()
                ->send();

            $action->halt();
        }
    }

    public static function openInZnunyAction(string $name = 'open_ticket'): Action
    {
        return Action::make($name)
            ->label(__('znuny_ticket_workspace.management_actions.open_ticket'))
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

                return $payload->lock === 'lock' ? __('znuny_ticket_workspace.management_actions.release') : __('znuny_ticket_workspace.management_actions.take');
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

                return $payload->lock === 'lock' ? __('znuny_ticket_workspace.management_actions.unlock_tooltip') : __('znuny_ticket_workspace.management_actions.lock_tooltip');
            })
            ->visible(function (array $arguments, $record = null) {
                if (auth()->user()?->canManageZnunyTickets() !== true) {
                    return false;
                }
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return (bool) $payload->znuny_ticket_id
                    && $payload->is_open
                    && in_array($payload->lock, ['lock', 'unlock'], true);
            })
            ->action(function (array $arguments, Action $action, $record = null) {
                abort_unless(auth()->user()?->canManageZnunyTickets(), 403);
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);
                if (! $payload->znuny_ticket_id) {
                    Notification::make()->title(__('znuny_ticket_workspace.management_actions.ticket_id_missing'))->danger()->send();
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
                            ->title($isLocked ? __('znuny_ticket_workspace.management_actions.release_failed') : __('znuny_ticket_workspace.management_actions.take_failed'))
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
                        ->title($isLocked ? __('znuny_ticket_workspace.management_actions.ticket_released') : __('znuny_ticket_workspace.management_actions.ticket_taken'))
                        ->success()
                        ->send();

                    $action->cancelParentActions();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title($isLocked ? __('znuny_ticket_workspace.management_actions.release_failed') : __('znuny_ticket_workspace.management_actions.take_failed'))
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
            ->label(__('znuny_ticket_workspace.management_actions.change_assignment_action'))
            ->icon('heroicon-o-users')
            ->color('gray')
            ->modalHeading(__('znuny_ticket_workspace.management_actions.change_assignment_heading'))
            ->form(function (array $arguments, $record = null) {
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return [
                    Placeholder::make('current_assignment_summary')
                        ->hiddenLabel()
                        ->content(function () use ($payload) {
                            $displayOwner = htmlspecialchars((string) $payload->znuny_owner_name);
                            if ($payload->znuny_owner_id) {
                                try {
                                    $ownerOptions = app(ZnunyAssignmentDependencyService::class)->getOwnerOptionsForQueue(null);
                                    if (isset($ownerOptions[$payload->znuny_owner_id])) {
                                        $displayOwner = htmlspecialchars($ownerOptions[$payload->znuny_owner_id]);
                                    }
                                } catch (\Throwable $e) {
                                }
                            }

                            return new HtmlString('
                            <div class="hidden lg:flex items-center gap-1.5 text-sm">
                                <span class="text-gray-500 dark:text-gray-500">'.__('znuny_ticket_workspace.management_actions.current').'</span>
                                <span class="text-gray-500 dark:text-gray-400">'.__('znuny_ticket_workspace.management_actions.queue').'</span>
                                <span class="font-semibold text-gray-700 dark:text-gray-300">'.htmlspecialchars((string) $payload->znuny_queue_name).'</span>
                                <span class="text-gray-300 dark:text-gray-600">&middot;</span>
                                <span class="text-gray-500 dark:text-gray-400">'.__('znuny_ticket_workspace.management_actions.owner').'</span>
                                <span class="font-semibold text-gray-700 dark:text-gray-300">'.$displayOwner.'</span>
                                <span class="text-gray-300 dark:text-gray-600">&middot;</span>
                                <span class="text-gray-500 dark:text-gray-400">'.__('znuny_ticket_workspace.management_actions.customer').'</span>
                                <span class="font-semibold text-gray-700 dark:text-gray-300">'.htmlspecialchars((string) $payload->customer_user).'</span>
                            </div>
                        ');
                        }),
                    Select::make('target_queue')
                        ->label(__('znuny_ticket_workspace.management_actions.target_queue'))
                        ->default($payload->znuny_queue_name)
                        ->required()
                        ->noOptionsMessage(function (ZnunyCachedLookupService $lookupService) {
                            $qState = $lookupService->getPrewarmDatasetState('queues');
                            $aState = $lookupService->getPrewarmDatasetState('agents');
                            if (! $qState['available'] || ! $aState['available']) {
                                return __('znuny_data_status.consumer.unavailable');
                            }

                            return __('znuny_ticket_workspace.management_actions.no_options_available');
                        })
                        ->helperText(function (ZnunyCachedLookupService $lookupService) {
                            $qState = $lookupService->getPrewarmDatasetState('queues');
                            $aState = $lookupService->getPrewarmDatasetState('agents');
                            if (! $qState['available'] || ! $aState['available']) {
                                return __('znuny_data_status.consumer.unavailable');
                            }
                            if ($qState['status'] === 'stale' || $aState['status'] === 'stale') {
                                return __('znuny_data_status.consumer.stale');
                            }
                            if ($qState['status'] === 'refreshing' || $aState['status'] === 'refreshing') {
                                return __('znuny_data_status.consumer.refreshing');
                            }

                            return null;
                        })
                        ->options(function ($get) use ($arguments, $record, $payload) {
                            $service = app(ZnunyAssignmentDependencyService::class);
                            $options = [];
                            try {
                                $options = $service->getQueueOptionsForOwnerLogin($get('target_owner'));
                            } catch (ConnectionException $e) {
                                static $notifiedQueue = false;
                                if (! $notifiedQueue) {
                                    $notifiedQueue = true;
                                    $payloadError = TicketDetailsPayload::fromRecord($record, $arguments);

                                    $curlCode = null;
                                    if ($e->getCode() > 0) {
                                        $curlCode = (int) $e->getCode();
                                    } elseif (preg_match('/^cURL error (\d+):/', $e->getMessage(), $matches)) {
                                        $curlCode = (int) $matches[1];
                                    }

                                    $context = [
                                        'operation' => 'agent_assignable_queues',
                                        'context' => 'change_assignment',
                                        'ticket_id' => $payloadError->znuny_ticket_id,
                                        'local_record_id' => $record?->getKey(),
                                        'agent_login' => $get('target_owner'),
                                        'exception' => class_basename($e),
                                        'category' => 'connection_timeout',
                                        'path' => '/Agent/{AgentID}/AssignableQueues',
                                    ];

                                    if ($curlCode > 0) {
                                        $context['curl_code'] = $curlCode;
                                    }

                                    AuditLogger::log(
                                        action: 'znuny.connection_failed',
                                        entityType: 'ZnunyTicket',
                                        entityId: $payloadError->znuny_ticket_id,
                                        context: $context
                                    );
                                    Notification::make()
                                        ->title(__('znuny_ticket_workspace.management_actions.queues_load_failed_title'))
                                        ->body(__('znuny_ticket_workspace.management_actions.queues_load_failed_body'))
                                        ->danger()
                                        ->send();
                                }
                            }

                            $currentQueue = $payload->znuny_queue_name;
                            if ($currentQueue && ! isset($options[$currentQueue])) {
                                $options[$currentQueue] = (string) $currentQueue;
                            }

                            return $options;
                        })
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function ($set, $get, ?string $state) {
                            $owner = $get('target_owner');
                            if ($owner && $state) {
                                $lookupService = app(ZnunyCachedLookupService::class);
                                $qState = $lookupService->getPrewarmDatasetState('queues');
                                $aState = $lookupService->getPrewarmDatasetState('agents');
                                if ($qState['available'] && $aState['available']) {
                                    $service = app(ZnunyAssignmentDependencyService::class);
                                    try {
                                        $validOptions = $service->getOwnerLoginOptionsForQueue($state);
                                        if (! array_key_exists($owner, $validOptions)) {
                                            $set('target_owner', null);
                                        }
                                    } catch (\Throwable $e) {
                                    }
                                }
                            }
                        }),

                    Select::make('target_owner')
                        ->label(__('znuny_ticket_workspace.management_actions.target_owner'))
                        ->default($payload->znuny_owner_name)
                        ->required()
                        ->noOptionsMessage(function (ZnunyCachedLookupService $lookupService) {
                            $qState = $lookupService->getPrewarmDatasetState('queues');
                            $aState = $lookupService->getPrewarmDatasetState('agents');
                            if (! $qState['available'] || ! $aState['available']) {
                                return __('znuny_data_status.consumer.unavailable');
                            }

                            return __('znuny_ticket_workspace.management_actions.no_options_available');
                        })
                        ->helperText(function (ZnunyCachedLookupService $lookupService) {
                            $qState = $lookupService->getPrewarmDatasetState('queues');
                            $aState = $lookupService->getPrewarmDatasetState('agents');
                            if (! $qState['available'] || ! $aState['available']) {
                                return __('znuny_data_status.consumer.unavailable');
                            }
                            if ($qState['status'] === 'stale' || $aState['status'] === 'stale') {
                                return __('znuny_data_status.consumer.stale');
                            }
                            if ($qState['status'] === 'refreshing' || $aState['status'] === 'refreshing') {
                                return __('znuny_data_status.consumer.refreshing');
                            }

                            return null;
                        })
                        ->options(function ($get) use ($payload) {
                            $service = app(ZnunyAssignmentDependencyService::class);
                            $options = [];
                            try {
                                $options = $service->getOwnerLoginOptionsForQueue($get('target_queue'));
                            } catch (\Throwable $e) {
                                $options = [];
                            }

                            $currentOwner = $payload->znuny_owner_name;
                            if ($currentOwner && ! isset($options[$currentOwner])) {
                                $options[$currentOwner] = (string) $currentOwner;
                            }

                            return $options;
                        })
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function ($set, $get, ?string $state) {
                            $queueName = $get('target_queue');
                            if ($queueName && $state) {
                                $lookupService = app(ZnunyCachedLookupService::class);
                                $qState = $lookupService->getPrewarmDatasetState('queues');
                                $aState = $lookupService->getPrewarmDatasetState('agents');
                                if ($qState['available'] && $aState['available']) {
                                    $service = app(ZnunyAssignmentDependencyService::class);
                                    try {
                                        $validOptions = $service->getQueueOptionsForOwnerLogin($state);
                                        if (! array_key_exists($queueName, $validOptions)) {
                                            $set('target_queue', null);
                                        }
                                    } catch (\Throwable $e) {
                                    }
                                }
                            }
                        }),

                    Select::make('target_customer')
                        ->label(__('znuny_ticket_workspace.management_actions.target_customer'))
                        ->default($payload->customer_user)
                        ->searchable()
                        ->noOptionsMessage(function (ZnunyCachedLookupService $lookupService) {
                            $state = $lookupService->getPrewarmDatasetState('customer_users');
                            if (! $state['available']) {
                                return __('znuny_data_status.consumer.customer_users_unavailable_search_live');
                            }

                            return __('znuny_ticket_workspace.management_actions.no_options_available');
                        })
                        ->helperText(function (ZnunyCachedLookupService $lookupService) {
                            $state = $lookupService->getPrewarmDatasetState('customer_users');
                            if (! $state['available']) {
                                return __('znuny_data_status.consumer.customer_users_unavailable_search_live');
                            }
                            if ($state['status'] === 'stale') {
                                return __('znuny_data_status.consumer.stale');
                            }
                            if ($state['status'] === 'refreshing') {
                                return __('znuny_data_status.consumer.refreshing');
                            }

                            return null;
                        })
                        ->getSearchResultsUsing(function (string $search, $get) {
                            $query = trim($search);
                            $lookupService = app(ZnunyCachedLookupService::class);
                            if ($query === '') {
                                $queue = $get('target_queue');
                                if (blank($queue)) {
                                    return [];
                                }
                                try {
                                    return $lookupService->getCustomerUserPrimaryOptionsForQueue($queue);
                                } catch (\Throwable $e) {
                                    return [];
                                }
                            }
                            try {
                                return $lookupService->searchCustomerUserOptions($query, 20);
                            } catch (\Throwable $e) {
                                return [];
                            }
                        })
                        ->getOptionLabelUsing(function ($value) {
                            if (empty($value)) {
                                return null;
                            }
                            $lookupService = app(ZnunyCachedLookupService::class);
                            try {
                                $label = $lookupService->getCustomerUserLabel($value);

                                return $label ?: $value;
                            } catch (\Throwable $e) {
                                return $value;
                            }
                        }),
                    Textarea::make('note')
                        ->label(__('znuny_ticket_workspace.management_actions.note'))
                        ->helperText(__('znuny_ticket_workspace.management_actions.note_helper')),
                ];
            })
            ->visible(function (array $arguments, $record = null) {
                if (auth()->user()?->canManageZnunyTickets() !== true) {
                    return false;
                }
                $payload = TicketDetailsPayload::fromRecord($record, $arguments);

                return $payload->znuny_ticket_id && $payload->is_open;
            })
            ->action(function (array $arguments, array $data, Action $action, $record = null) {
                abort_unless(auth()->user()?->canManageZnunyTickets(), 403);
                $payloadInfo = TicketDetailsPayload::fromRecord($record, $arguments);
                if (! $payloadInfo->znuny_ticket_id) {
                    Notification::make()->title(__('znuny_ticket_workspace.management_actions.ticket_id_missing'))->danger()->send();
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
                    $agentService = app(ZnunyAgentService::class);
                    if ($agentService->isLoginExcluded($data['target_owner'])) {
                        Notification::make()->title(__('znuny_ticket_workspace.management_actions.invalid_owner'))->danger()->send();
                        $action->halt();

                        return;
                    }

                    $requestPayload['OwnerLogin'] = $data['target_owner'];
                    $hasChange = true;
                }

                if (! empty($data['target_customer']) && $data['target_customer'] !== $payloadInfo->customer_user) {
                    $requestPayload['CustomerUserID'] = $data['target_customer'];
                    $hasChange = true;
                }

                if (! $hasChange) {
                    Notification::make()->title(__('znuny_ticket_workspace.management_actions.no_changes_made'))->warning()->send();
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
                        $messages[] = __('znuny_ticket_workspace.management_actions.note_required');
                    }
                    if (empty($messages)) {
                        $messages = ['Validation failed'];
                    }

                    Notification::make()
                        ->title(__('znuny_ticket_workspace.management_actions.validation_failed'))
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
                        ->title(__('znuny_ticket_workspace.management_actions.update_failed'))
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
                            ->title(__('znuny_ticket_workspace.management_actions.assignment_changed_note_failed'))
                            ->warning()
                            ->send();
                    }
                }

                try {
                    $refreshService = app(ZnunyTicketWorkspaceTicketRefreshService::class);
                    $refreshService->refreshTicket($payloadInfo->znuny_ticket_id);

                    Notification::make()
                        ->title(__('znuny_ticket_workspace.management_actions.assignment_changed'))
                        ->success()
                        ->send();

                    $action->cancelParentActions();
                } catch (\Exception $e) {
                    Log::error('Ticket workspace refresh failed after assignment change: '.$e->getMessage(), ['ticket_id' => $payloadInfo->znuny_ticket_id, 'exception' => $e]);

                    Notification::make()
                        ->title(__('znuny_ticket_workspace.management_actions.assignment_changed_refresh_failed'))
                        ->body($e->getMessage())
                        ->warning()
                        ->send();
                }
            });
    }
}
