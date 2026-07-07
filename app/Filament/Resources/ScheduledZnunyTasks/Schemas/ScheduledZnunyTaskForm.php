<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Schemas;

use App\Services\Cron\CronService;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyClient;
use Carbon\Carbon;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ScheduledZnunyTaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Task Details')
                    ->schema([
                        TextInput::make('name')
                            ->label('Task Name')
                            ->required(),
                        Toggle::make('enabled')
                            ->label('Enabled')
                            ->helperText('Enable this task to run on the given schedule.')
                            ->default(false),
                    ])->columns(2),

                Section::make('Schedule')
                    ->schema([
                        TextInput::make('cron_expression')
                            ->label('Cron Expression')
                            ->placeholder('* * * * *')
                            ->required(fn ($get) => $get('enabled') === true)
                            ->rules([
                                function () {
                                    return function (string $attribute, $value, \Closure $fail) {
                                        if (! empty($value) && ! app(CronService::class)->isValid($value)) {
                                            $fail('Invalid 5-field cron expression.');
                                        }
                                    };
                                },
                            ])
                            ->live()
                            ->afterStateUpdated(function ($state, $set, $get) {
                                if (empty($state) || ! app(CronService::class)->isValid($state)) {
                                    $set('next_run_at', null);
                                } else {
                                    $set('next_run_at', app(CronService::class)->calculateNextRun($state, $get('timezone')));
                                }
                            }),
                        Select::make('timezone')
                            ->label('Timezone')
                            ->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))
                            ->default(config('app.timezone'))
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, $set, $get) {
                                $cron = $get('cron_expression');
                                if (! empty($cron) && app(CronService::class)->isValid($cron)) {
                                    $set('next_run_at', app(CronService::class)->calculateNextRun($cron, $state));
                                }
                            }),
                        Placeholder::make('next_run_at_placeholder')
                            ->label('Next Run Preview')
                            ->content(fn ($get) => $get('next_run_at') ? Carbon::parse($get('next_run_at'))->format('Y-m-d H:i:s') : 'N/A'),
                    ])->columns(3),

                Section::make('Ticket Details Overrides')
                    ->description('These fields override the global Advanced Ticket Preset defaults when the task creates a ticket.')
                    ->schema([
                        Select::make('queue_name')
                            ->label('Queue')
                            ->required(fn ($get) => $get('enabled') === true)
                            ->searchable()
                            ->preload()
                            ->optionsLimit(1000)
                            ->live()
                            ->afterStateUpdated(function ($state, $set, ZnunyCachedLookupService $lookupService) {
                                $set('owner_login', null);
                                $set('customer_user_login', null);

                                if ($state) {
                                    $candidate = $lookupService->resolveTemplateCandidate($state);
                                    if ($candidate) {
                                        $set('customer_user_login', $candidate);
                                    }
                                }
                            })
                            ->options(function (ZnunyCachedLookupService $lookupService) {
                                try {
                                    return $lookupService->getFilteredQueueOptions();
                                } catch (\Throwable $e) {
                                    return [];
                                }
                            }),
                        Select::make('owner_login')
                            ->label('Owner')
                            ->searchable()
                            ->preload()
                            ->options(function ($get, ZnunyCachedLookupService $lookupService) {
                                try {
                                    return $lookupService->getAssignableOwnerOptionsForQueue($get('queue_name') ?? '');
                                } catch (\Throwable $e) {
                                    return [];
                                }
                            }),
                        Select::make('customer_user_login')
                            ->label('Customer User')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->key(fn ($get) => 'customer-user-'.($get('queue_name') ?: 'none'))
                            ->options(function ($get, ZnunyCachedLookupService $lookupService) {
                                $queue = $get('queue_name');
                                if (empty($queue)) {
                                    return [];
                                }
                                try {
                                    $options = $lookupService->getCustomerUserPrimaryOptionsForQueue($queue);
                                } catch (\Throwable $e) {
                                    $options = [];
                                }
                                $current = $get('customer_user_login');
                                if ($current && ! isset($options[$current])) {
                                    try {
                                        $label = $lookupService->getCustomerUserLabel($current);
                                        if ($label) {
                                            $options[$current] = $label;
                                        }
                                    } catch (\Throwable $e) {
                                    }
                                }

                                return $options;
                            })
                            ->getSearchResultsUsing(function (string $search, $get, ZnunyCachedLookupService $lookupService, ZnunyClient $client) {
                                $query = trim($search);
                                if ($query === '') {
                                    $queue = $get('queue_name');
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
                                    return collect($client->searchCustomerUsers($query))
                                        ->pluck('label', 'login')
                                        ->toArray();
                                } catch (\Throwable $e) {
                                    return [];
                                }
                            })
                            ->getOptionLabelUsing(function ($value, $get, ZnunyCachedLookupService $lookupService) {
                                if (empty($value)) {
                                    return null;
                                }
                                $queue = $get('queue_name');
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
                                    return $value;
                                }
                            }),
                        Select::make('type_name')
                            ->label('Type')
                            ->searchable()
                            ->preload()
                            ->options(function (ZnunyCachedLookupService $lookupService) {
                                try {
                                    return $lookupService->getTicketTypes();
                                } catch (\Throwable $e) {
                                    return [];
                                }
                            }),
                        Select::make('priority_name')
                            ->label('Priority')
                            ->searchable()
                            ->preload()
                            ->options(function (ZnunyCachedLookupService $lookupService) {
                                try {
                                    return $lookupService->getTicketPriorities();
                                } catch (\Throwable $e) {
                                    return [];
                                }
                            }),
                        Select::make('state_name')
                            ->label('State')
                            ->searchable()
                            ->preload()
                            ->options(function (ZnunyCachedLookupService $lookupService) {
                                try {
                                    return $lookupService->getTicketStates();
                                } catch (\Throwable $e) {
                                    return [];
                                }
                            }),
                        Select::make('service_name')
                            ->label('Service')
                            ->searchable()
                            ->preload()
                            ->options(function (ZnunyCachedLookupService $lookupService) {
                                try {
                                    return $lookupService->getTicketServices();
                                } catch (\Throwable $e) {
                                    return [];
                                }
                            })
                            ->live()
                            ->afterStateUpdated(fn ($set) => $set('sla_name', null)),
                        Select::make('sla_name')
                            ->label('SLA')
                            ->searchable()
                            ->preload()
                            ->options(function ($get, ZnunyCachedLookupService $lookupService) {
                                try {
                                    return $lookupService->getTicketSLAsForService($get('service_name') ?? '');
                                } catch (\Throwable $e) {
                                    return [];
                                }
                            }),
                    ])->columns(2),

                Section::make('Ticket Content')
                    ->schema([
                        TextInput::make('subject')
                            ->label('Subject')
                            ->required(fn ($get) => $get('enabled') === true),
                        Textarea::make('body')
                            ->label('Body')
                            ->rows(5)
                            ->required(fn ($get) => $get('enabled') === true),
                    ]),
            ]);
    }
}
