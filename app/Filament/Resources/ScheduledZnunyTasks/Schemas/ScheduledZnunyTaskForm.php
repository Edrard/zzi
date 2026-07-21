<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Schemas;

use App\Services\Cron\CronService;
use App\Services\Support\DateTimeDisplayService;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketAdvancedDefaultsService;
use Carbon\Carbon;
use Filament\Forms\Components\Hidden;
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
        $updateNextRun = function ($set, $get) {
            $cron = $get('cron_expression');
            $tz = $get('timezone');
            if (! empty($cron) && app(CronService::class)->isValid($cron)) {
                $next = app(CronService::class)->calculateNextRun($cron, $tz);
                $set('next_run_at', $next ? $next->utc()->toDateTimeString() : null);
            } else {
                $set('next_run_at', null);
            }
        };

        return $schema
            ->components([
                Section::make(__('scheduled_znuny_tasks.form.sections.task_details'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('scheduled_znuny_tasks.form.task_name'))
                            ->required(),
                        Toggle::make('enabled')
                            ->label(__('scheduled_znuny_tasks.form.enabled'))
                            ->helperText(__('scheduled_znuny_tasks.form.enabled_help'))
                            ->default(false),
                    ])->columns(2),

                Section::make(__('scheduled_znuny_tasks.form.sections.schedule'))
                    ->schema([
                        TextInput::make('cron_expression')
                            ->label(__('scheduled_znuny_tasks.form.cron'))
                            ->placeholder('* * * * *')
                            ->required(fn ($get) => $get('enabled') === true)
                            ->rules([
                                function () {
                                    return function (string $attribute, $value, \Closure $fail) {
                                        if (! empty($value) && ! app(CronService::class)->isValid($value)) {
                                            $fail(__('scheduled_znuny_tasks.form.invalid_cron'));
                                        }
                                    };
                                },
                            ])
                            ->live()
                            ->afterStateHydrated($updateNextRun)
                            ->afterStateUpdated($updateNextRun),
                        Select::make('timezone')
                            ->label(__('scheduled_znuny_tasks.form.timezone'))
                            ->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))
                            ->default(fn () => app(DateTimeDisplayService::class)->timezone())
                            ->searchable()
                            ->required(fn ($get) => $get('enabled') === true)
                            ->live()
                            ->afterStateHydrated($updateNextRun)
                            ->afterStateUpdated($updateNextRun),
                        Placeholder::make('next_run_at_placeholder')
                            ->label(__('scheduled_znuny_tasks.form.next_run_preview'))
                            ->content(function ($get) {
                                $runAt = $get('next_run_at');
                                if (! $runAt) {
                                    return __('scheduled_znuny_tasks.form.n_a');
                                }
                                $tz = $get('timezone') ?: config('app.timezone');

                                return Carbon::parse($runAt)->timezone($tz)->format('Y-m-d H:i:s')." {$tz}";
                            }),
                        Hidden::make('next_run_at'),
                    ])->columns(3),

                Section::make(__('scheduled_znuny_tasks.form.sections.ticket_overrides'))
                    ->description(__('scheduled_znuny_tasks.form.overrides_desc'))
                    ->schema([
                        Select::make('queue_name')
                            ->label(__('scheduled_znuny_tasks.form.queue'))
                            ->required(fn ($get) => $get('enabled') === true)
                            ->searchable()
                            ->preload()
                            ->optionsLimit(1000)
                            ->live()
                            ->afterStateUpdated(function ($state, $set, ZnunyCachedLookupService $lookupService) {
                                $set('owner_login', null);
                                $set('owner_id', null);
                                $set('customer_user_login', null);

                                if ($state) {
                                    $candidate = $lookupService->resolveTemplateCandidate($state);
                                    if ($candidate) {
                                        $set('customer_user_login', $candidate);
                                    }

                                    $ownerOptions = $lookupService->getAssignableOwnerOptionsForQueue($state);
                                    if (count($ownerOptions) === 1) {
                                        $onlyOwnerKey = array_key_first($ownerOptions);
                                        $onlyOwnerLabel = $ownerOptions[$onlyOwnerKey];
                                        if (is_numeric($onlyOwnerKey) && $onlyOwnerKey > 0) {
                                            $set('owner_id', (int) $onlyOwnerKey);
                                            $set('owner_login', (string) $onlyOwnerLabel);
                                        }
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
                        Select::make('owner_id')
                            ->label(__('scheduled_znuny_tasks.form.owner'))
                            ->required(fn ($get) => $get('enabled') === true)
                            ->searchable()
                            ->preload()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, $set, $get, ZnunyCachedLookupService $lookupService) {
                                if (empty($state)) {
                                    $set('owner_login', null);

                                    return;
                                }
                                try {
                                    $options = $lookupService->getAssignableOwnerOptionsForQueue($get('queue_name') ?? '');
                                    $label = $options[$state] ?? null;
                                    $set('owner_login', $label ? (string) $label : null);
                                } catch (\Throwable $e) {
                                    $set('owner_login', null);
                                }
                            })
                            ->options(function ($get, ZnunyCachedLookupService $lookupService) {
                                try {
                                    $options = $lookupService->getAssignableOwnerOptionsForQueue($get('queue_name') ?? '');
                                    $current = $get('owner_id');
                                    // owner_login display fallback for currently selected option if not in queue options
                                    $currentDisplay = $get('owner_login') ?: $current;
                                    if ($current && ! isset($options[$current])) {
                                        $options[$current] = $currentDisplay;
                                    }

                                    return $options;
                                } catch (\Throwable $e) {
                                    return [];
                                }
                            }),
                        Hidden::make('owner_login'),
                        Select::make('customer_user_login')
                            ->label(__('scheduled_znuny_tasks.form.customer_user'))
                            ->required(fn ($get) => $get('enabled') === true)
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
                        Select::make('priority_name')
                            ->label(__('scheduled_znuny_tasks.form.priority'))
                            ->searchable()
                            ->preload()
                            ->default(fn () => app(ZnunyTicketAdvancedDefaultsService::class)->getDefaults()['priority'])
                            ->options(function (ZnunyCachedLookupService $lookupService) {
                                try {
                                    return $lookupService->getTicketPriorities();
                                } catch (\Throwable $e) {
                                    return [];
                                }
                            }),
                        Select::make('state_name')
                            ->label(__('scheduled_znuny_tasks.form.state'))
                            ->searchable()
                            ->preload()
                            ->default(fn () => app(ZnunyTicketAdvancedDefaultsService::class)->getDefaults()['state'])
                            ->options(function (ZnunyCachedLookupService $lookupService) {
                                try {
                                    return $lookupService->getTicketStates();
                                } catch (\Throwable $e) {
                                    return [];
                                }
                            }),
                        Select::make('lock_name')
                            ->label(__('scheduled_znuny_tasks.form.lock'))
                            ->searchable()
                            ->preload()
                            ->default(fn () => app(ZnunyTicketAdvancedDefaultsService::class)->getDefaults()['lock'])
                            ->options([
                                'lock' => 'Lock',
                                'unlock' => 'Unlock',
                            ]),
                    ])->columns(2),

                Section::make(__('scheduled_znuny_tasks.form.sections.ticket_content'))
                    ->schema([
                        TextInput::make('subject')
                            ->label(__('scheduled_znuny_tasks.form.subject'))
                            ->required(fn ($get) => $get('enabled') === true),
                        Textarea::make('body')
                            ->label(__('scheduled_znuny_tasks.form.body'))
                            ->rows(5)
                            ->required(fn ($get) => $get('enabled') === true),
                    ]),
            ]);
    }
}
