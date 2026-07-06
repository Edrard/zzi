<?php

namespace App\Filament\Schemas;

use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyClient;
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
            Section::make('Ticket Details')->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('queue')
                        ->label('Queue')
                        ->required()
                        ->searchable()
                        ->preload()
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
                        ->label('Owner')
                        ->searchable()
                        ->preload()
                        ->options(function ($get, ZnunyCachedLookupService $lookupService) {
                            try {
                                return $lookupService->getAssignableOwnerOptionsForQueue($get('queue') ?? '');
                            } catch (\Throwable $e) {
                                report($e);

                                return [];
                            }
                        }),
                    Select::make('customer_user')
                        ->label('Customer User')
                        ->required()
                        ->searchable()
                        ->preload()
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
                        ->getSearchResultsUsing(function (string $search, $get, ZnunyCachedLookupService $lookupService, ZnunyClient $client) {
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
                                return collect($client->searchCustomerUsers($query))
                                    ->pluck('label', 'login')
                                    ->toArray();
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
                    ->label('Title')
                    ->required(),
                Textarea::make('body')
                    ->label('Article Body')
                    ->required()
                    ->rows(5),
            ]),

            Section::make('Advanced ticket options')
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        Select::make('priority')
                            ->label('Priority')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(fn (ZnunyTicketAdvancedDefaultsService $defaultsService) => $defaultsService->getDefaults()['priority'])
                            ->options(function (ZnunyCachedLookupService $lookupService) {
                                try {
                                    return $lookupService->getTicketPriorities();
                                } catch (\Throwable $e) {
                                    report($e);

                                    return [];
                                }
                            }),
                        Select::make('state')
                            ->label('State')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(fn (ZnunyTicketAdvancedDefaultsService $defaultsService) => $defaultsService->getDefaults()['state'])
                            ->options(function (ZnunyCachedLookupService $lookupService) {
                                try {
                                    return $lookupService->getTicketStates();
                                } catch (\Throwable $e) {
                                    report($e);

                                    return [];
                                }
                            }),
                        Select::make('lock')
                            ->label('Lock')
                            ->required()
                            ->default(fn (ZnunyTicketAdvancedDefaultsService $defaultsService) => $defaultsService->getDefaults()['lock'])
                            ->options([
                                'lock' => 'Lock',
                                'unlock' => 'Unlock',
                            ]),
                    ]),
                ])
                ->collapsible()
                ->collapsed(),
        ];
    }
}
