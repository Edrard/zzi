<?php

namespace App\Filament\Schemas;

use App\Filament\Pages\CreateTicket;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyTicketAdvancedDefaultsService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

class ZnunyTicketCreationSchema
{
    public static function schema(): array
    {
        return [
            Section::make(__('create_ticket.sections.ticket_details'))->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('queue')
                        ->label(__('create_ticket.fields.queue'))
                        ->required()
                        ->searchable()
                        ->preload()
                        ->noOptionsMessage(function (ZnunyCachedLookupService $lookupService) {
                            $state = $lookupService->getPrewarmDatasetState('queues');
                            if (! $state['available']) {
                                return __('znuny_data_status.consumer.unavailable');
                            }

                            return __('create_ticket.messages.no_options_available');
                        })
                        ->helperText(function (ZnunyCachedLookupService $lookupService) {
                            $state = $lookupService->getPrewarmDatasetState('queues');
                            if (! $state['available']) {
                                return __('znuny_data_status.consumer.unavailable');
                            }
                            if ($state['status'] === 'stale') {
                                return __('znuny_data_status.consumer.stale');
                            }
                            if ($state['status'] === 'refreshing') {
                                return __('znuny_data_status.consumer.refreshing');
                            }

                            return null;
                        })
                        ->optionsLimit(1000)
                        ->live()
                        ->afterStateUpdated(function ($state, $set, ZnunyCachedLookupService $lookupService) {
                            $set('owner', null);
                            $set('customer_user', null);

                            if ($state) {
                                $candidate = $lookupService->resolveTemplateCandidate($state);
                                if ($candidate) {
                                    $set('customer_user', $candidate);
                                }
                            }
                        })
                        ->options(function (ZnunyCachedLookupService $lookupService) {
                            try {
                                return $lookupService->getFilteredQueueOptions();
                            } catch (\Throwable $e) {
                                report($e);

                                return [];
                            }
                        }),
                    Select::make('owner')
                        ->label(__('create_ticket.fields.owner'))
                        ->required()
                        ->searchable()
                        ->preload()
                        ->noOptionsMessage(function (ZnunyCachedLookupService $lookupService) {
                            $qState = $lookupService->getPrewarmDatasetState('queues');
                            $aState = $lookupService->getPrewarmDatasetState('agents');
                            if (! $qState['available'] || ! $aState['available']) {
                                return __('znuny_data_status.consumer.unavailable');
                            }

                            return __('create_ticket.messages.no_options_available');
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
                        ->options(function ($get, ZnunyCachedLookupService $lookupService) {
                            try {
                                return $lookupService->getAssignableHumanOwnerOptionsForQueue($get('queue') ?? '');
                            } catch (\Throwable $e) {
                                report($e);

                                return [];
                            }
                        }),
                    Select::make('customer_user')
                        ->label(__('create_ticket.fields.customer_user'))
                        ->required()
                        ->searchable()
                        ->preload()
                        ->noOptionsMessage(function (ZnunyCachedLookupService $lookupService) {
                            $state = $lookupService->getPrewarmDatasetState('customer_users');
                            if (! $state['available']) {
                                return __('znuny_data_status.consumer.customer_users_unavailable_search_live');
                            }

                            return __('create_ticket.messages.no_options_available');
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
                        ->live()
                        ->key(fn ($get) => 'customer-user-'.($get('queue') ?: 'none'))
                        ->options(function ($get, ZnunyCachedLookupService $lookupService) {
                            $queue = $get('queue');
                            if (empty($queue)) {
                                return [];
                            }

                            $options = [];
                            try {
                                $options = $lookupService->getCustomerUserPrimaryOptionsForQueue($queue);
                            } catch (\Throwable $e) {
                                report($e);
                            }

                            $current = $get('customer_user');
                            if ($current && ! isset($options[$current])) {
                                try {
                                    $label = $lookupService->getCustomerUserLabel($current);
                                    if ($label) {
                                        $options[$current] = $label;
                                    }
                                } catch (\Throwable $e) {
                                    report($e);
                                }
                            }

                            return $options;
                        })
                        ->getSearchResultsUsing(function (string $search, $get, ZnunyCachedLookupService $lookupService) {
                            $query = trim($search);

                            if ($query === '') {
                                $queue = $get('queue');

                                if (blank($queue)) {
                                    return [];
                                }

                                try {
                                    return $lookupService->getCustomerUserPrimaryOptionsForQueue($queue);
                                } catch (\Throwable $e) {
                                    report($e);

                                    return [];
                                }
                            }

                            try {
                                return $lookupService->searchCustomerUserOptions($query);
                            } catch (\Throwable $e) {
                                report($e);

                                return [];
                            }
                        })
                        ->getOptionLabelUsing(function ($value, $get, ZnunyCachedLookupService $lookupService) {
                            if (empty($value)) {
                                return null;
                            }

                            $queue = $get('queue');
                            if ($queue) {
                                try {
                                    $options = $lookupService->getCustomerUserPrimaryOptionsForQueue($queue);
                                    if (isset($options[$value])) {
                                        return $options[$value];
                                    }
                                } catch (\Throwable $e) {
                                }
                            }

                            try {
                                $label = $lookupService->getCustomerUserLabel($value);

                                return $label ?: $value;
                            } catch (\Throwable $e) {
                                report($e);

                                return $value;
                            }
                        }),
                ]),
                TextInput::make('title')
                    ->label(__('create_ticket.fields.title'))
                    ->required(),
                Textarea::make('body')
                    ->label(__('create_ticket.fields.body'))
                    ->required()
                    ->rows(5),
            ]),

            Section::make(__('create_ticket.sections.advanced_options'))
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        Select::make('priority')
                            ->label(__('create_ticket.fields.priority'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->noOptionsMessage(function (ZnunyCachedLookupService $lookupService) {
                                $state = $lookupService->getPrewarmDatasetState('lookups');
                                if (! $state['available']) {
                                    return __('znuny_data_status.consumer.unavailable');
                                }

                                return __('create_ticket.messages.no_options_available');
                            })
                            ->helperText(function (ZnunyCachedLookupService $lookupService) {
                                $state = $lookupService->getPrewarmDatasetState('lookups');
                                if (! $state['available']) {
                                    return __('znuny_data_status.consumer.unavailable');
                                }
                                if ($state['status'] === 'stale') {
                                    return __('znuny_data_status.consumer.stale');
                                }
                                if ($state['status'] === 'refreshing') {
                                    return __('znuny_data_status.consumer.refreshing');
                                }

                                return null;
                            })
                            ->default(fn (ZnunyTicketAdvancedDefaultsService $defaultsService) => $defaultsService->getDefaults()['priority'])
                            ->options(function (ZnunyCachedLookupService $lookupService) {
                                try {
                                    return collect($lookupService->getTicketPriorities())
                                        ->mapWithKeys(fn ($label, $key) => [$key => CreateTicket::priorityLabel($key)])
                                        ->toArray();
                                } catch (\Throwable $e) {
                                    report($e);

                                    return [];
                                }
                            }),
                        Select::make('state')
                            ->label(__('create_ticket.fields.state'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->noOptionsMessage(function (ZnunyCachedLookupService $lookupService) {
                                $state = $lookupService->getPrewarmDatasetState('lookups');
                                if (! $state['available']) {
                                    return __('znuny_data_status.consumer.unavailable');
                                }

                                return __('create_ticket.messages.no_options_available');
                            })
                            ->helperText(function (ZnunyCachedLookupService $lookupService) {
                                $state = $lookupService->getPrewarmDatasetState('lookups');
                                if (! $state['available']) {
                                    return __('znuny_data_status.consumer.unavailable');
                                }
                                if ($state['status'] === 'stale') {
                                    return __('znuny_data_status.consumer.stale');
                                }
                                if ($state['status'] === 'refreshing') {
                                    return __('znuny_data_status.consumer.refreshing');
                                }

                                return null;
                            })
                            ->default(fn (ZnunyTicketAdvancedDefaultsService $defaultsService) => $defaultsService->getDefaults()['state'])
                            ->options(function (ZnunyCachedLookupService $lookupService) {
                                try {
                                    return collect($lookupService->getTicketStates())
                                        ->mapWithKeys(fn ($label, $key) => [$key => CreateTicket::stateLabel($key)])
                                        ->toArray();
                                } catch (\Throwable $e) {
                                    report($e);

                                    return [];
                                }
                            }),
                        Select::make('lock')
                            ->label(__('create_ticket.fields.lock'))
                            ->required()
                            ->noOptionsMessage(__('create_ticket.messages.no_options_available'))
                            ->default(fn (ZnunyTicketAdvancedDefaultsService $defaultsService) => $defaultsService->getDefaults()['lock'])
                            ->options(collect([
                                'lock' => 'Lock',
                                'unlock' => 'Unlock',
                            ])->mapWithKeys(fn ($label, $key) => [$key => CreateTicket::lockLabel($key)])->toArray()),
                    ]),
                ])
                ->collapsible()
                ->collapsed(),
        ];
    }
}
