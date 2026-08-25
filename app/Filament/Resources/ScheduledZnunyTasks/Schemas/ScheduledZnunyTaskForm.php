<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Schemas;

use App\Services\Cron\CronService;
use App\Services\Support\DateTimeDisplayService;
use App\Services\Znuny\ZnunyCachedLookupService;
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
                            ->noOptionsMessage(function (ZnunyCachedLookupService $lookupService) {
                                $state = $lookupService->getPrewarmDatasetState('queues');
                                if (! $state['available']) {
                                    return __('znuny_data_status.consumer.unavailable');
                                }

                                return __('scheduled_znuny_tasks.form.no_options');
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
                                $set('owner_login', null);
                                $set('owner_id', null);
                                $set('customer_user_login', null);

                                if ($state) {
                                    $candidate = $lookupService->resolveTemplateCandidate($state);
                                    if ($candidate) {
                                        $set('customer_user_login', $candidate);
                                    }

                                    $ownerOptions = $lookupService->getAssignableHumanOwnerOptionsForQueue($state);
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
                            ->options(function ($get, ZnunyCachedLookupService $lookupService) {
                                try {
                                    $options = $lookupService->getFilteredQueueOptions();
                                } catch (\Throwable $e) {
                                    $options = [];
                                }

                                $current = $get('queue_name');
                                if ($current && ! isset($options[$current])) {
                                    $options[$current] = (string) $current;
                                }

                                return $options;
                            }),
                        Select::make('owner_id')
                            ->label(__('scheduled_znuny_tasks.form.owner'))
                            ->required(fn ($get) => $get('enabled') === true)
                            ->searchable()
                            ->preload()
                            ->noOptionsMessage(function (ZnunyCachedLookupService $lookupService) {
                                $qState = $lookupService->getPrewarmDatasetState('queues');
                                $aState = $lookupService->getPrewarmDatasetState('agents');
                                if (! $qState['available'] || ! $aState['available']) {
                                    return __('znuny_data_status.consumer.unavailable');
                                }

                                return __('scheduled_znuny_tasks.form.no_options');
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
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, $set, $get, ZnunyCachedLookupService $lookupService) {
                                if (empty($state)) {
                                    $set('owner_login', null);

                                    return;
                                }
                                try {
                                    $options = $lookupService->getAssignableHumanOwnerOptionsForQueue($get('queue_name') ?? '');
                                    $label = $options[$state] ?? null;
                                    $set('owner_login', $label ? (string) $label : null);
                                } catch (\Throwable $e) {
                                    $set('owner_login', null);
                                }
                            })
                            ->options(function ($get, ZnunyCachedLookupService $lookupService) {
                                try {
                                    $options = $lookupService->getAssignableHumanOwnerOptionsForQueue($get('queue_name') ?? '');
                                } catch (\Throwable $e) {
                                    $options = [];
                                }

                                $current = $get('owner_id');
                                if ($current && ! isset($options[$current])) {
                                    $fallback = $get('owner_login') ?: null;
                                    $options[$current] = $lookupService->getCanonicalOwnerLabel((int) $current, $fallback);
                                }

                                return $options;
                            }),
                        Hidden::make('owner_login'),
                        Select::make('customer_user_login')
                            ->label(__('scheduled_znuny_tasks.form.customer_user'))
                            ->required(fn ($get) => $get('enabled') === true)
                            ->searchable()
                            ->preload()
                            ->noOptionsMessage(function (ZnunyCachedLookupService $lookupService) {
                                $state = $lookupService->getPrewarmDatasetState('customer_users');
                                if (! $state['available']) {
                                    return __('znuny_data_status.consumer.customer_users_unavailable_search_live');
                                }

                                return __('scheduled_znuny_tasks.form.no_options');
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
                            ->key(fn ($get) => 'customer-user-'.($get('queue_name') ?: 'none'))
                            ->options(function ($get, ZnunyCachedLookupService $lookupService) {
                                $queue = $get('queue_name');
                                $options = [];

                                if (! empty($queue)) {
                                    try {
                                        $options = $lookupService->getCustomerUserPrimaryOptionsForQueue($queue);
                                    } catch (\Throwable $e) {
                                        $options = [];
                                    }
                                }

                                $current = $get('customer_user_login');
                                if ($current && ! isset($options[$current])) {
                                    $label = null;
                                    try {
                                        $label = $lookupService->getCustomerUserLabel($current);
                                    } catch (\Throwable $e) {
                                    }

                                    if ($label) {
                                        $options[$current] = $label;
                                    } else {
                                        $options[$current] = (string) $current;
                                    }
                                }

                                return $options;
                            })
                            ->getSearchResultsUsing(function (string $search, $get, ZnunyCachedLookupService $lookupService) {
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
                                    return $lookupService->searchCustomerUserOptions($query);
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
                            ->noOptionsMessage(function (ZnunyCachedLookupService $lookupService) {
                                $state = $lookupService->getPrewarmDatasetState('lookups');
                                if (! $state['available']) {
                                    return __('znuny_data_status.consumer.unavailable');
                                }

                                return __('scheduled_znuny_tasks.form.no_options');
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
                            ->default(fn () => app(ZnunyTicketAdvancedDefaultsService::class)->getDefaults()['priority'])
                            ->options(function ($get, ZnunyCachedLookupService $lookupService) {
                                try {
                                    $options = $lookupService->getTicketPriorities();
                                } catch (\Throwable $e) {
                                    $options = [];
                                }

                                $current = $get('priority_name');
                                if ($current && ! isset($options[$current])) {
                                    $options[$current] = (string) $current;
                                }

                                return $options;
                            }),
                        Select::make('state_name')
                            ->label(__('scheduled_znuny_tasks.form.state'))
                            ->searchable()
                            ->preload()
                            ->noOptionsMessage(function (ZnunyCachedLookupService $lookupService) {
                                $state = $lookupService->getPrewarmDatasetState('lookups');
                                if (! $state['available']) {
                                    return __('znuny_data_status.consumer.unavailable');
                                }

                                return __('scheduled_znuny_tasks.form.no_options');
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
                            ->default(fn () => app(ZnunyTicketAdvancedDefaultsService::class)->getDefaults()['state'])
                            ->options(function ($get, ZnunyCachedLookupService $lookupService) {
                                try {
                                    $options = $lookupService->getTicketStates();
                                } catch (\Throwable $e) {
                                    $options = [];
                                }

                                $current = $get('state_name');
                                if ($current && ! isset($options[$current])) {
                                    $options[$current] = (string) $current;
                                }

                                return $options;
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
