<?php

namespace App\Filament\Resources\ZabbixTickets\Schemas;

use App\Filament\Resources\ZabbixTickets\ZabbixTicketResource;
use App\Filament\Support\TicketDetailsPayload;
use App\Services\AuditLogger;
use App\Services\Support\DateTimeDisplayService;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\ZnunyAssignmentDependencyService;
use App\Services\Znuny\ZnunyCustomerUserEditService;
use App\Services\Znuny\ZnunyCustomerUserExistenceService;
use App\Services\Znuny\ZnunyCustomerUserQuickCreateService;
use App\Services\Znuny\ZnunyCustomerUserUrlService;
use App\Services\Znuny\ZnunyTicketArticleCacheService;
use App\Services\Znuny\ZnunyTicketCacheReconciliationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Livewire\Component;

class ZabbixTicketInfolist
{
    private static function formatLabel(string $label): HtmlString
    {
        return new HtmlString('<span style="color: light-dark(#6b7280, #bbb); font-weight: 400; font-size: 0.875rem;">'.e($label).'</span>');
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'sm' => 2])
                    ->schema([
                        Group::make([
                            Section::make(__('zabbix_tickets.details_modal.sections.ticket'))
                                ->compact()
                                ->schema([
                                    TextEntry::make('znuny_ticket_number')->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.number')))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->znuny_ticket_number)->inlineLabel()->placeholder(__('zabbix_tickets.details_modal.placeholders.empty')),
                                    TextEntry::make('title')->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.title')))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->title)->inlineLabel()->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->title !== null)->placeholder(__('zabbix_tickets.details_modal.placeholders.empty')),
                                    TextEntry::make('created_at')->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.created_at')))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->created_at)->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->diffForHumans($state))->inlineLabel()->placeholder(__('zabbix_tickets.details_modal.placeholders.empty')),
                                    TextEntry::make('changed_at')->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.updated_at')))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->changed_at)->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->diffForHumans($state))->inlineLabel()->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->changed_at !== null),
                                    TextEntry::make('manual_reopened_at')->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.reopened_at')))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->manual_reopened_at)->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatLocalizedDateTime($state))->inlineLabel()->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->manual_reopened_at !== null),
                                    TextEntry::make('resolution_context')
                                        ->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.context')))
                                        ->state(function ($record) {
                                            $context = TicketDetailsPayload::fromRecord($record)->resolution_context;

                                            return ZabbixTicketResource::translateZabbixStatus($context)['label'] ?? null;
                                        })
                                        ->badge()
                                        ->color(fn ($record) => TicketDetailsPayload::fromRecord($record)->resolution_context['color'] ?? 'gray')
                                        ->icon(fn ($record) => TicketDetailsPayload::fromRecord($record)->resolution_context['icon'] ?? null)
                                        ->tooltip(function ($record) {
                                            $context = TicketDetailsPayload::fromRecord($record)->resolution_context;

                                            return ZabbixTicketResource::translateZabbixStatus($context)['tooltip'] ?? null;
                                        })
                                        ->inlineLabel(),
                                    TextEntry::make('zabbix_problem_resolved_at')
                                        ->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.resolved_at')))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->zabbix_problem_resolved_at ? app(DateTimeDisplayService::class)->formatLocalizedDateTime(TicketDetailsPayload::fromRecord($record)->zabbix_problem_resolved_at) : null)
                                        ->visible(fn ($record) => ! empty(TicketDetailsPayload::fromRecord($record)->zabbix_problem_resolved_at))
                                        ->inlineLabel(),
                                    TextEntry::make('manual_close_eligible_at')
                                        ->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.auto_close_at')))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->manual_close_eligible_at ? app(DateTimeDisplayService::class)->formatLocalizedDateTime(TicketDetailsPayload::fromRecord($record)->manual_close_eligible_at) : null)
                                        ->visible(fn ($record) => ! empty(TicketDetailsPayload::fromRecord($record)->manual_close_eligible_at))
                                        ->inlineLabel(),
                                    TextEntry::make('znuny_ticket_closed_at')
                                        ->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.closed_at')))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->znuny_ticket_closed_at ? app(DateTimeDisplayService::class)->formatLocalizedDateTime(TicketDetailsPayload::fromRecord($record)->znuny_ticket_closed_at) : null)
                                        ->visible(fn ($record) => ! empty(TicketDetailsPayload::fromRecord($record)->znuny_ticket_closed_at))
                                        ->inlineLabel(),
                                    TextEntry::make('manual_flap_count')
                                        ->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.flap_count')))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->manual_flap_count)
                                        ->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->manual_flap_count > 0)
                                        ->inlineLabel(),
                                    TextEntry::make('manual_last_flap_counted_at')
                                        ->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.last_flap_at')))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->manual_last_flap_counted_at ? app(DateTimeDisplayService::class)->formatLocalizedDateTime(TicketDetailsPayload::fromRecord($record)->manual_last_flap_counted_at) : null)
                                        ->visible(fn ($record) => ! empty(TicketDetailsPayload::fromRecord($record)->manual_last_flap_counted_at))
                                        ->inlineLabel(),
                                ])->columns(1),
                        ]),

                        Group::make([
                            Section::make(__('zabbix_tickets.details_modal.sections.znuny_attributes'))
                                ->compact()
                                ->schema([
                                    TextEntry::make('znuny_queue_name')->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.queue')))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->znuny_queue_name)->inlineLabel()->placeholder(__('zabbix_tickets.details_modal.placeholders.empty')),
                                    TextEntry::make('znuny_owner_name')->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.owner')))->state(function ($record) {
                                        $payload = TicketDetailsPayload::fromRecord($record);
                                        $displayOwner = $payload->znuny_owner_name;
                                        if ($payload->znuny_owner_id) {
                                            try {
                                                $ownerOptions = app(ZnunyAssignmentDependencyService::class)->getOwnerOptionsForQueue(null);
                                                if (isset($ownerOptions[$payload->znuny_owner_id])) {
                                                    $displayOwner = $ownerOptions[$payload->znuny_owner_id];
                                                }
                                            } catch (\Throwable $e) {
                                            }
                                        }

                                        return $displayOwner;
                                    })->inlineLabel()->placeholder(__('zabbix_tickets.details_modal.placeholders.empty')),
                                    TextEntry::make('customer_user')
                                        ->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.customer')))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->customer_user)
                                        ->inlineLabel()
                                        ->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->customer_user !== null)
                                        ->placeholder(__('zabbix_tickets.details_modal.placeholders.empty'))
                                        ->icon(function ($record) {
                                            $registered = TicketDetailsPayload::fromRecord($record)->customer_user_registered;
                                            if ($registered === true) {
                                                return 'heroicon-m-user';
                                            }
                                            if ($registered === false) {
                                                return 'heroicon-m-user-plus';
                                            }

                                            return null;
                                        })
                                        ->iconColor(function ($record) {
                                            $registered = TicketDetailsPayload::fromRecord($record)->customer_user_registered;
                                            if ($registered === false) {
                                                return 'warning';
                                            }

                                            return 'gray';
                                        })
                                        ->tooltip(function ($record) {
                                            $registered = TicketDetailsPayload::fromRecord($record)->customer_user_registered;
                                            if ($registered === true) {
                                                return __('zabbix_tickets.details_modal.customer_user_edit.tooltip');
                                            }
                                            if ($registered === false) {
                                                return __('zabbix_tickets.details_modal.customer_user_quick_create.tooltip_create');
                                            }

                                            return null;
                                        })
                                        ->action(
                                            Action::make('manage_customer_user')
                                                ->modalHeading(fn ($record) => TicketDetailsPayload::fromRecord($record)->customer_user_registered ? __('zabbix_tickets.details_modal.customer_user_edit.modal_heading') : __('zabbix_tickets.details_modal.customer_user_quick_create.modal_heading_create'))
                                                ->mountUsing(function (Schema $schema, $record, Action $action, Component $livewire) {
                                                    $payload = TicketDetailsPayload::fromRecord($record);
                                                    $login = $payload->customer_user;

                                                    if (empty($login)) {
                                                        $action->cancel();

                                                        return;
                                                    }

                                                    $existenceService = app(ZnunyCustomerUserExistenceService::class);
                                                    $reconciliationService = app(ZnunyTicketCacheReconciliationService::class);
                                                    $wasRegistered = $payload->customer_user_registered === true;
                                                    $ticketId = $payload->znuny_ticket_id ? (int) $payload->znuny_ticket_id : null;

                                                    if ($wasRegistered) {
                                                        $service = app(ZnunyCustomerUserEditService::class);
                                                        $result = $service->getCustomerUser($login, $ticketId);

                                                        if (! $result['success']) {
                                                            if ($result['message'] === __('zabbix_tickets.details_modal.customer_user_edit.errors.not_found')) {
                                                                try {
                                                                    AuditLogger::log(
                                                                        'znuny.customer_user.not_found',
                                                                        'znuny_customer_user',
                                                                        $login,
                                                                        [
                                                                            'source' => 'ticket_customer_user_verify',
                                                                            'znuny_ticket_id' => $ticketId,
                                                                            'customer_user_login' => $login,
                                                                            'customer_id' => $payload->customer_id ?? null,
                                                                            'local_registered_before' => true,
                                                                            'direct_lookup' => 'not_found',
                                                                        ]
                                                                    );
                                                                } catch (\Throwable $e) {
                                                                    Log::error('AuditLogger failed during GRAY NOT FOUND verification', [
                                                                        'exception_class' => get_class($e),
                                                                    ]);
                                                                }
                                                            }

                                                            Notification::make()
                                                                ->title(__('zabbix_tickets.details_modal.customer_user_edit.notifications.error_title'))
                                                                ->body($result['message'])
                                                                ->danger()
                                                                ->send();

                                                            $action->cancel();

                                                            return;
                                                        }

                                                        $schema->fill($result['data']);

                                                        return;
                                                    } else {
                                                        $result = $existenceService->lookupDirectly($login, $payload->customer_id ?? '');

                                                        if ($result['source'] === ZnunyCustomerUserExistenceService::SOURCE_UNAVAILABLE) {
                                                            Notification::make()
                                                                ->title(__('zabbix_tickets.details_modal.customer_user_edit.notifications.error_title'))
                                                                ->body(__('zabbix_tickets.details_modal.customer_user_edit.notifications.api_unavailable'))
                                                                ->danger()
                                                                ->send();
                                                            $action->cancel();

                                                            return;
                                                        }

                                                        $isFound = $result['registered'] === true;

                                                        if ($isFound) {
                                                            $resolvedLogin = trim((string) ($result['login'] ?? ''));
                                                            $resolvedCustomerId = trim((string) ($result['customer_id'] ?? ''));

                                                            if (
                                                                $resolvedLogin === ''
                                                                || strcasecmp($resolvedLogin, trim((string) $login)) !== 0
                                                                || $resolvedCustomerId === ''
                                                            ) {
                                                                Notification::make()
                                                                    ->title(__('zabbix_tickets.details_modal.customer_user_edit.notifications.error_title'))
                                                                    ->body(__('zabbix_tickets.details_modal.customer_user_edit.notifications.api_unavailable'))
                                                                    ->danger()
                                                                    ->send();
                                                                $action->cancel();

                                                                return;
                                                            }

                                                            Log::info(
                                                                'CustomerUser local state corrected from direct Znuny lookup.',
                                                                [
                                                                    'ticket_id' => $ticketId,
                                                                    'login' => $resolvedLogin,
                                                                    'customer_id' => $resolvedCustomerId,
                                                                ],
                                                            );

                                                            $reconciliationService->reconcileKnownCustomerUserIdentity($login, $resolvedLogin, $resolvedCustomerId, $ticketId);

                                                            try {
                                                                AuditLogger::log(
                                                                    'znuny.customer_user.self_healed',
                                                                    'znuny_customer_user',
                                                                    $resolvedLogin,
                                                                    [
                                                                        'source' => 'ticket_customer_user_self_heal',
                                                                        'znuny_ticket_id' => $ticketId,
                                                                        'customer_user_login' => $resolvedLogin,
                                                                        'customer_id' => $resolvedCustomerId,
                                                                        'local_registered_before' => false,
                                                                        'direct_lookup' => 'found',
                                                                        'local_ticket_reconciliation' => true,
                                                                    ]
                                                                );
                                                            } catch (\Throwable $e) {
                                                                Log::error('AuditLogger failed during ORANGE FOUND self-heal', [
                                                                    'exception_class' => get_class($e),
                                                                ]);
                                                            }

                                                            Notification::make()
                                                                ->title(__('zabbix_tickets.details_modal.customer_user_edit.notifications.success_title'))
                                                                ->body(__('zabbix_tickets.details_modal.customer_user_edit.errors.already_exists_updated'))
                                                                ->success()
                                                                ->send();

                                                            $parentIndex = count($livewire->mountedActions) - 2;
                                                            $parentMountedAction = $parentIndex >= 0
                                                                ? ($livewire->mountedActions[$parentIndex] ?? null)
                                                                : null;

                                                            TicketDetailsPayload::clearCache();

                                                            $parentActionName = is_array($parentMountedAction)
                                                                ? ($parentMountedAction['name'] ?? null)
                                                                : null;

                                                            if (is_string($parentActionName) && $parentActionName !== '') {
                                                                $livewire->replaceMountedAction(
                                                                    $parentActionName,
                                                                    $parentMountedAction['arguments'] ?? [],
                                                                    $parentMountedAction['context'] ?? [],
                                                                );
                                                            } else {
                                                                $livewire->unmountAction(cancelParentActions: false);
                                                            }

                                                            return;
                                                        } else {
                                                            $schema->fill([
                                                                'login' => $login,
                                                                'email' => $login,
                                                            ]);

                                                            return;
                                                        }
                                                    }
                                                })
                                                ->form(function ($record) {
                                                    $payload = TicketDetailsPayload::fromRecord($record);
                                                    $isRegistered = $payload->customer_user_registered;
                                                    $readOnlyStyle = ['style' => 'background-color: rgba(128, 128, 128, 0.1); cursor: not-allowed; opacity: 0.7;'];

                                                    return [
                                                        TextInput::make('email')
                                                            ->label(__('zabbix_tickets.details_modal.customer_user_quick_create.fields.email'))
                                                            ->email()
                                                            ->required()
                                                            ->readOnly(fn () => ! $isRegistered)
                                                            ->extraInputAttributes(fn () => ! $isRegistered ? $readOnlyStyle : []),
                                                        TextInput::make('login')
                                                            ->label(__('zabbix_tickets.details_modal.customer_user_quick_create.fields.login'))
                                                            ->required()
                                                            ->readOnly(fn () => ! $isRegistered)
                                                            ->extraInputAttributes(fn () => ! $isRegistered ? $readOnlyStyle : [])
                                                            ->helperText(fn () => ! $isRegistered ? __('zabbix_tickets.details_modal.customer_user_quick_create.fields.login_helper') : null),
                                                        TextInput::make('first_name')
                                                            ->label(__('zabbix_tickets.details_modal.customer_user_quick_create.fields.first_name'))
                                                            ->required(),
                                                        TextInput::make('last_name')
                                                            ->label(__('zabbix_tickets.details_modal.customer_user_quick_create.fields.last_name'))
                                                            ->required(),
                                                        Select::make('customer_id')
                                                            ->label(__('zabbix_tickets.details_modal.customer_user_quick_create.fields.customer_id'))
                                                            ->searchable()
                                                            ->optionsLimit(100)
                                                            ->options(function () {
                                                                $companies = app(ZnunyLookupCacheReadService::class)->getCustomerCompanies();
                                                                $options = [];
                                                                foreach ($companies as $id => $name) {
                                                                    $customerId = trim((string) $id);
                                                                    $companyName = trim((string) $name);
                                                                    $prefix = $customerId.' ';

                                                                    if (
                                                                        $customerId !== ''
                                                                        && str_starts_with(strtolower($companyName), strtolower($prefix))
                                                                    ) {
                                                                        $companyName = trim(substr($companyName, strlen($prefix)));
                                                                    }

                                                                    $options[$customerId] = $companyName !== ''
                                                                        ? $companyName.' ('.$customerId.')'
                                                                        : $customerId;
                                                                }

                                                                return $options;
                                                            })
                                                            ->required(),
                                                    ];
                                                })
                                                ->modalSubmitAction(function (Action $action, $record) {
                                                    $payload = TicketDetailsPayload::fromRecord($record);

                                                    return $action->label($payload->customer_user_registered ? __('zabbix_tickets.details_modal.customer_user_edit.action_label') : __('zabbix_tickets.details_modal.customer_user_quick_create.action_label'));
                                                })
                                                ->label(function ($record) {
                                                    $payload = TicketDetailsPayload::fromRecord($record);

                                                    return $payload->customer_user_registered
                                                        ? __('zabbix_tickets.details_modal.customer_user_edit.actions.edit_customer_user')
                                                        : __('zabbix_tickets.details_modal.customer_user_quick_create.actions.create_missing_user');
                                                })
                                                ->modalCancelAction(function (Action $action, $record) {
                                                    $payload = TicketDetailsPayload::fromRecord($record);

                                                    return $action->label($payload->customer_user_registered ? __('zabbix_tickets.details_modal.customer_user_edit.action_cancel') : __('zabbix_tickets.details_modal.customer_user_quick_create.action_cancel'));
                                                })
                                                ->extraModalFooterActions(function ($record) {
                                                    $payload = TicketDetailsPayload::fromRecord($record);
                                                    if (! $payload->customer_user_registered) {
                                                        return [];
                                                    }

                                                    $login = $payload->customer_user;
                                                    $url = app(ZnunyCustomerUserUrlService::class)->getEditUrl($login);

                                                    if (! $url) {
                                                        return [];
                                                    }

                                                    return [
                                                        Action::make('open_in_znuny')
                                                            ->label(__('zabbix_tickets.details_modal.customer_user_edit.native_link'))
                                                            ->url($url)
                                                            ->openUrlInNewTab()
                                                            ->color('gray'),
                                                    ];
                                                })
                                                ->modalFooterActionsAlignment(Alignment::Right)
                                                ->modalFooterActions(fn (Action $action) => array_merge(
                                                    [
                                                        $action->getModalSubmitAction(),
                                                        $action->getModalCancelAction(),
                                                    ],
                                                    $action->getExtraModalFooterActions()
                                                ))
                                                ->action(function (array $data, $record, Action $action, Component $livewire) {
                                                    $payload = TicketDetailsPayload::fromRecord($record);
                                                    $isRegistered = $payload->customer_user_registered;

                                                    if ($isRegistered) {
                                                        $service = app(ZnunyCustomerUserEditService::class);
                                                        $result = $service->updateCustomerUser(
                                                            $payload->customer_user ?? '',
                                                            [
                                                                'Login' => $data['login'] ?? '',
                                                                'Email' => $data['email'] ?? '',
                                                                'FirstName' => $data['first_name'] ?? '',
                                                                'LastName' => $data['last_name'] ?? '',
                                                                'CustomerID' => $data['customer_id'] ?? '',
                                                            ],
                                                            $payload->znuny_ticket_id ? (int) $payload->znuny_ticket_id : null
                                                        );
                                                    } else {
                                                        $service = app(ZnunyCustomerUserQuickCreateService::class);
                                                        $result = $service->createCustomerUser(
                                                            $data['login'] ?? '',
                                                            $data['email'] ?? '',
                                                            $data['first_name'] ?? '',
                                                            $data['last_name'] ?? '',
                                                            $data['customer_id'] ?? '',
                                                            $payload->znuny_ticket_id ? (int) $payload->znuny_ticket_id : null
                                                        );
                                                    }

                                                    if ($result['success']) {

                                                        $notification = Notification::make()
                                                            ->title(! empty($result['warning'])
                                                                ? __('zabbix_tickets.details_modal.customer_user_quick_create.notifications.warning_title')
                                                                : ($isRegistered ? __('zabbix_tickets.details_modal.customer_user_edit.notifications.success_title') : __('zabbix_tickets.details_modal.customer_user_quick_create.notifications.success_title')))
                                                            ->body($result['message']);

                                                        if (! empty($result['warning'])) {
                                                            $notification->warning();
                                                        } else {
                                                            $notification->success();
                                                        }

                                                        $notification->send();

                                                        $parentIndex = count($livewire->mountedActions) - 2;
                                                        $parentMountedAction = $parentIndex >= 0
                                                            ? ($livewire->mountedActions[$parentIndex] ?? null)
                                                            : null;

                                                        TicketDetailsPayload::clearCache();

                                                        $parentActionName = is_array($parentMountedAction)
                                                            ? ($parentMountedAction['name'] ?? null)
                                                            : null;

                                                        if (is_string($parentActionName) && $parentActionName !== '') {
                                                            $livewire->replaceMountedAction(
                                                                $parentActionName,
                                                                $parentMountedAction['arguments'] ?? [],
                                                                $parentMountedAction['context'] ?? [],
                                                            );
                                                        } else {
                                                            $livewire->unmountAction(cancelParentActions: false);
                                                        }
                                                    } else {
                                                        Notification::make()
                                                            ->title($isRegistered ? __('zabbix_tickets.details_modal.customer_user_edit.notifications.error_title') : __('zabbix_tickets.details_modal.customer_user_quick_create.notifications.error_title'))
                                                            ->body($result['message'])
                                                            ->danger()
                                                            ->send();

                                                        $action->halt();
                                                    }
                                                })
                                                ->visible(function ($record) {
                                                    $payload = TicketDetailsPayload::fromRecord($record);

                                                    return $payload->customer_user_registered !== null;
                                                })
                                        ),
                                    TextEntry::make('znuny_priority')->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.priority')))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->znuny_priority)->inlineLabel()->placeholder(__('zabbix_tickets.details_modal.placeholders.empty')),
                                    TextEntry::make('znuny_state_name')->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.state')))->state(function ($record) {
                                        $state = TicketDetailsPayload::fromRecord($record)->znuny_state_name;

                                        return ZabbixTicketResource::translateZnunyState($state);
                                    })->inlineLabel()->placeholder(__('zabbix_tickets.details_modal.placeholders.empty')),
                                    TextEntry::make('lock_status')->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.lock_status')))->state(function ($record) {
                                        $lock = TicketDetailsPayload::fromRecord($record)->lock;
                                        if ($lock === 'lock') {
                                            return __('zabbix_tickets.details_modal.lock_statuses.locked');
                                        } elseif ($lock === 'unlock') {
                                            return __('zabbix_tickets.details_modal.lock_statuses.unlocked');
                                        }

                                        return __('zabbix_tickets.details_modal.lock_statuses.unknown');
                                    })->inlineLabel(),
                                    TextEntry::make('last_article')->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.last_article')))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->last_article)->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatLocalizedDateTime($state))->inlineLabel()->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->last_article !== null)->placeholder(__('zabbix_tickets.details_modal.placeholders.empty')),
                                ])->columns(1),

                            Section::make(__('zabbix_tickets.details_modal.sections.zabbix'))
                                ->compact()
                                ->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->has_zabbix_link)
                                ->schema([
                                    TextEntry::make('zabbix_host_name')->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.host')))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->zabbix_host_name)->inlineLabel()->placeholder(__('zabbix_tickets.details_modal.placeholders.empty')),
                                    TextEntry::make('zabbix_problem_name')->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.problem')))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->zabbix_problem_name)->inlineLabel()->placeholder(__('zabbix_tickets.details_modal.placeholders.empty')),
                                    TextEntry::make('zabbix_event_id')->label(self::formatLabel(__('zabbix_tickets.details_modal.fields.event_id')))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->zabbix_event_id)->inlineLabel()->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->zabbix_event_id !== null)->placeholder(__('zabbix_tickets.details_modal.placeholders.empty')),
                                ])->columns(1),
                        ]),
                    ]),
                Section::make(__('zabbix_tickets.details_modal.sections.articles_notes'))
                    ->compact()
                    ->schema([
                        ViewEntry::make('articles_notes')
                            ->hiddenLabel()
                            ->getStateUsing(function ($record) {
                                $payload = TicketDetailsPayload::fromRecord($record);
                                if (! $payload->znuny_ticket_id) {
                                    return [];
                                }

                                $articles = app(ZnunyTicketArticleCacheService::class)->get($payload->znuny_ticket_id);

                                foreach ($articles as &$article) {
                                    if (! empty($article['created_at'])) {
                                        $article['created_at'] = TicketDetailsPayload::parseZnunyTimestamp($article['created_at']);
                                    }
                                }
                                unset($article);

                                usort($articles, function ($a, $b) {
                                    if (! empty($a['article_id']) && ! empty($b['article_id'])) {
                                        return $b['article_id'] <=> $a['article_id'];
                                    }

                                    $aDate = $a['created_at'] ?? null;
                                    $bDate = $b['created_at'] ?? null;

                                    if ($aDate instanceof Carbon && $bDate instanceof Carbon) {
                                        return $bDate <=> $aDate;
                                    }

                                    return 0;
                                });

                                return $articles;
                            })
                            ->view('filament.infolists.articles-accordion'),
                    ]),
            ]);
    }
}
