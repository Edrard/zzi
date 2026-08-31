<?php

namespace App\Filament\Resources\ZabbixTickets\Schemas;

use App\Filament\Resources\ZabbixTickets\ZabbixTicketResource;
use App\Filament\Support\TicketDetailsPayload;
use App\Services\Support\DateTimeDisplayService;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\ZnunyAssignmentDependencyService;
use App\Services\Znuny\ZnunyCustomerUserQuickCreateService;
use App\Services\Znuny\ZnunyCustomerUserUrlService;
use App\Services\Znuny\ZnunyTicketArticleCacheService;
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
                                                return __('zabbix_tickets.details_modal.customer_user_quick_create.tooltip_open');
                                            }
                                            if ($registered === false) {
                                                return __('zabbix_tickets.details_modal.customer_user_quick_create.tooltip_create');
                                            }

                                            return null;
                                        })
                                        ->action(
                                            Action::make('manage_customer_user')
                                                ->url(function ($record) {
                                                    $payload = TicketDetailsPayload::fromRecord($record);
                                                    if ($payload->customer_user_registered) {
                                                        $login = $payload->customer_user;

                                                        return app(ZnunyCustomerUserUrlService::class)->getEditUrl($login);
                                                    }

                                                    return null;
                                                })
                                                ->openUrlInNewTab()
                                                ->modalHeading(fn ($record) => TicketDetailsPayload::fromRecord($record)->customer_user_registered ? __('zabbix_tickets.details_modal.customer_user_quick_create.modal_heading_edit') : __('zabbix_tickets.details_modal.customer_user_quick_create.modal_heading_create'))
                                                ->form(function ($record) {
                                                    $payload = TicketDetailsPayload::fromRecord($record);
                                                    if ($payload->customer_user_registered) {
                                                        // Registered branch should not render a modal. Handled by url() unless disabled/missing.
                                                        // Returning an empty array gracefully prevents rendering the old full edit stub.
                                                        return [];
                                                    } else {
                                                        return [
                                                            TextInput::make('email')
                                                                ->label(__('zabbix_tickets.details_modal.customer_user_quick_create.fields.email'))
                                                                ->email()
                                                                ->default($payload->customer_user)
                                                                ->readOnly(),
                                                            TextInput::make('login')
                                                                ->label(__('zabbix_tickets.details_modal.customer_user_quick_create.fields.login'))
                                                                ->default($payload->customer_user)
                                                                ->readOnly(),
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
                                                                        $options[$id] = $name.' ('.$id.')';
                                                                    }

                                                                    return $options;
                                                                })
                                                                ->required(),
                                                        ];
                                                    }
                                                })
                                                ->modalSubmitAction(function (Action $action, $record) {
                                                    $payload = TicketDetailsPayload::fromRecord($record);
                                                    if ($payload->customer_user_registered) {
                                                        return false;
                                                    }

                                                    return $action->label(__('zabbix_tickets.details_modal.customer_user_quick_create.action_label'));
                                                })
                                                ->action(function (array $data, $record, Action $action, Component $livewire) {
                                                    $payload = TicketDetailsPayload::fromRecord($record);
                                                    if ($payload->customer_user_registered) {
                                                        return;
                                                    }

                                                    $service = app(ZnunyCustomerUserQuickCreateService::class);

                                                    $result = $service->createCustomerUser(
                                                        $data['login'] ?? '',
                                                        $data['email'] ?? '',
                                                        $data['first_name'] ?? '',
                                                        $data['last_name'] ?? '',
                                                        $data['customer_id'] ?? '',
                                                        $payload->znuny_ticket_id ? (int) $payload->znuny_ticket_id : null
                                                    );

                                                    if ($result['success']) {
                                                        $notification = Notification::make()
                                                            ->title(! empty($result['warning'])
                                                                ? __('zabbix_tickets.details_modal.customer_user_quick_create.notifications.warning_title')
                                                                : __('zabbix_tickets.details_modal.customer_user_quick_create.notifications.success_title'))
                                                            ->body($result['message']);

                                                        if (! empty($result['warning'])) {
                                                            $notification->warning();
                                                        } else {
                                                            $notification->success();
                                                        }

                                                        $notification->send();

                                                        // Redis is already reconciled by the service, but the mounted parent
                                                        // action still owns the old record/schema snapshot. Re-mount that same
                                                        // parent action so Filament resolves a fresh record from the updated cache.
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
                                                            // Defensive fallback: only close the Quick Create child.
                                                            $livewire->unmountAction(cancelParentActions: false);
                                                        }
                                                    } else {
                                                        Notification::make()
                                                            ->title(__('zabbix_tickets.details_modal.customer_user_quick_create.notifications.error_title'))
                                                            ->body($result['message'])
                                                            ->danger()
                                                            ->send();

                                                        $action->halt();
                                                    }
                                                })
                                                ->modalCancelAction(fn ($action) => $action->label(__('zabbix_tickets.details_modal.customer_user_quick_create.action_cancel')))
                                                ->modalFooterActionsAlignment(Alignment::Right)
                                                ->modalFooterActions(fn (Action $action) => [
                                                    $action->getModalSubmitAction(),
                                                    $action->getModalCancelAction(),
                                                ])
                                                ->visible(function ($record) {
                                                    $payload = TicketDetailsPayload::fromRecord($record);
                                                    if ($payload->customer_user_registered === null) {
                                                        return false;
                                                    }
                                                    if ($payload->customer_user_registered === false) {
                                                        return true;
                                                    }

                                                    $login = $payload->customer_user;

                                                    return app(ZnunyCustomerUserUrlService::class)->getEditUrl($login) !== null;
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
