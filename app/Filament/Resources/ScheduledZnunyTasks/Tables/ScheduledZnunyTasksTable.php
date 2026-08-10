<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Tables;

use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use App\Models\ScheduledZnunyTask;
use App\Services\Cron\CronService;
use App\Services\Support\DateTimeDisplayService;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Support\ScheduledZnunyTasksRequestProfiler;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScheduledZnunyTasksTable
{
    private static function prepareQueueOptions(): void
    {
        if (app()->has('scheduled_tasks_queue_options')) {
            return;
        }

        app(ScheduledZnunyTasksRequestProfiler::class)->measure('prepare_options', function () {
            $lookupService = app(ZnunyCachedLookupService::class);

            $queueOptions = app(ScheduledZnunyTasksRequestProfiler::class)->measure('queue_lookup', function () use ($lookupService) {
                return $lookupService->getFilteredQueueOptions();
            });

            app()->instance('scheduled_tasks_queue_options', $queueOptions);
        });
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->recordClasses(fn (ScheduledZnunyTask $record) => match ($record->enabled) {
                false => 'scheduled-task-disabled-row scheduled-znuny-row',
                default => 'scheduled-znuny-row',
            })
            ->modifyQueryUsing(function (Builder $query) use ($table) {
                $livewire = $table->getLivewire();

                if (method_exists($livewire, 'getTaskSearch') && ! empty($livewire->getTaskSearch())) {
                    $s = $livewire->getTaskSearch();
                    $query->where(function ($q) use ($s) {
                        $q->where('name', 'like', "%{$s}%")
                            ->orWhere('subject', 'like', "%{$s}%")
                            ->orWhere('queue_name', 'like', "%{$s}%")
                            ->orWhere('owner_login', 'like', "%{$s}%")
                            ->orWhere('last_status', 'like', "%{$s}%");
                    });
                }

                if (method_exists($livewire, 'getQueueFilter') && ! empty($livewire->getQueueFilter())) {
                    $query->where('queue_name', $livewire->getQueueFilter());
                }

                if (method_exists($livewire, 'getOwnerFilter') && $livewire->getOwnerFilter() !== '' && $livewire->getOwnerFilter() !== 'all') {
                    $query->where('owner_id', (int) $livewire->getOwnerFilter());
                }

                if (method_exists($livewire, 'getActiveFilter') && $livewire->getActiveFilter() !== 'all') {
                    $query->where('enabled', $livewire->getActiveFilter() === '1');
                }

                $query->orderBy('queue_name', 'asc');
                $query->orderBy('id', 'desc');
            })
            ->columns([
                ToggleColumn::make('enabled')
                    ->label(__('scheduled_znuny_tasks.table.active'))
                    ->extraHeaderAttributes(['class' => 'scheduled-znuny-header'])
                    ->extraCellAttributes(fn (ScheduledZnunyTask $record) => ['data-scheduled-sort-value' => $record->enabled ? '1' : '0'])
                    ->rules(function (ScheduledZnunyTask $record) {
                        return [
                            function (string $attribute, $value, \Closure $fail) use ($record) {
                                if ($value) {
                                    if (! $record->isCompleteForScheduling()) {
                                        $missing = $record->missingSchedulingRequirements();
                                        Notification::make()
                                            ->title(__('scheduled_znuny_tasks.notifications.cannot_enable_task.title'))
                                            ->body(__('scheduled_znuny_tasks.notifications.cannot_enable_task.body_incomplete')."\n- ".implode("\n- ", $missing))
                                            ->danger()
                                            ->send();
                                        $fail('Task is incomplete.');

                                        return;
                                    }

                                    $cronService = app(CronService::class);
                                    $nextRunAt = $cronService->calculateNextRun($record->cron_expression, $record->timezone);
                                    if ($nextRunAt === null) {
                                        Notification::make()
                                            ->title(__('scheduled_znuny_tasks.notifications.cannot_enable_task.title'))
                                            ->body(__('scheduled_znuny_tasks.notifications.cannot_enable_task.body_invalid_cron'))
                                            ->danger()
                                            ->send();
                                        $fail('Invalid cron or timezone.');
                                    }
                                }
                            },
                        ];
                    })
                    ->updateStateUsing(function (ScheduledZnunyTask $record, $state) {
                        $cronService = app(CronService::class);

                        // We calculate the next run for preview purposes even if the task is disabled.
                        // When enabling, this also calculates a fresh time from NOW, preventing catch-up.
                        if (! empty($record->cron_expression) && $cronService->isValid($record->cron_expression)) {
                            $next = $cronService->calculateNextRun($record->cron_expression, $record->timezone);
                            $record->next_run_at = $next ? $next->utc()->toDateTimeString() : null;
                        } else {
                            $record->next_run_at = null;
                        }

                        $record->enabled = $state;
                        $record->save();
                    }),
                TextColumn::make('name')
                    ->label(__('scheduled_znuny_tasks.table.name'))
                    ->extraHeaderAttributes(['class' => 'scheduled-znuny-header'])
                    ->description(fn (ScheduledZnunyTask $record) => $record->subject)
                    ->extraCellAttributes(fn (ScheduledZnunyTask $record) => ['data-scheduled-sort-value' => $record->name ?? '']),
                TextInputColumn::make('cron_expression')
                    ->label(__('scheduled_znuny_tasks.table.cron'))
                    ->extraAttributes(['class' => 'scheduled-znuny-cron'])
                    ->extraHeaderAttributes(['class' => 'scheduled-znuny-header'])
                    ->extraCellAttributes(fn (ScheduledZnunyTask $record) => ['data-scheduled-sort-value' => $record->cron_expression ?? ''])
                    ->rules([
                        function () {
                            return function (string $attribute, $value, \Closure $fail) {
                                if (! empty($value) && ! app(CronService::class)->isValid($value)) {
                                    $fail('Invalid 5-field cron expression.');
                                }
                            };
                        },
                    ])
                    ->updateStateUsing(function (ScheduledZnunyTask $record, $state) {
                        $cronService = app(CronService::class);
                        if (empty($state) || ! $cronService->isValid($state)) {
                            Notification::make()
                                ->title(__('scheduled_znuny_tasks.notifications.validation_error.title'))
                                ->body(__('scheduled_znuny_tasks.notifications.validation_error.invalid_cron'))
                                ->danger()
                                ->send();

                            $record->next_run_at = null;
                            $record->save();

                            return;
                        }

                        $record->cron_expression = $state;
                        $next = $cronService->calculateNextRun($state, $record->timezone);
                        $record->next_run_at = $next ? $next->utc()->toDateTimeString() : null;
                        $record->save();
                    }),
                TextColumn::make('next_run_at')
                    ->label(__('scheduled_znuny_tasks.table.next_run_at'))
                    ->extraHeaderAttributes(['class' => 'scheduled-znuny-header'])
                    ->extraCellAttributes(function (?string $state) {
                        if (empty($state)) {
                            return ['data-scheduled-sort-value' => ''];
                        }

                        return ['data-scheduled-sort-value' => Carbon::parse($state, 'UTC')->timestamp];
                    })
                    ->getStateUsing(function (ScheduledZnunyTask $record) {
                        return app(ScheduledZnunyTasksRequestProfiler::class)->measure('cron_display', function () use ($record) {
                            $cron = $record->cron_expression;
                            $tz = $record->timezone;

                            $cronService = app(CronService::class);
                            if (empty($cron) || empty($tz) || ! $cronService->isValid($cron)) {
                                return null;
                            }

                            $next = $cronService->calculateNextRun($cron, $tz);

                            return $next ? $next->utc()->toDateTimeString() : null;
                        });
                    })
                    ->formatStateUsing(function (?string $state) {
                        if (empty($state)) {
                            return null;
                        }

                        return app(DateTimeDisplayService::class)->formatDateTime($state);
                    })
                    ->placeholder(__('scheduled_znuny_tasks.placeholders.not_calculated')),
                SelectColumn::make('queue_name')
                    ->label(__('scheduled_znuny_tasks.table.queue'))
                    ->placeholder(__('scheduled_znuny_tasks.placeholders.not_selected'))
                    ->extraAttributes(['class' => 'scheduled-znuny-select'])
                    ->extraHeaderAttributes(['class' => 'scheduled-znuny-header'])
                    ->options(function () {
                        self::prepareQueueOptions();

                        return app('scheduled_tasks_queue_options') ?? [];
                    })
                    ->updateStateUsing(function (ScheduledZnunyTask $record, $state) {
                        if ($record->enabled && empty($state)) {
                            Notification::make()->title(__('scheduled_znuny_tasks.notifications.cannot_clear_queue.title'))->body(__('scheduled_znuny_tasks.notifications.cannot_clear_queue.body'))->danger()->send();

                            return ['error' => 'Queue is required for active tasks.'];
                        }

                        $record->queue_name = empty($state) ? null : $state;
                        $record->owner_login = null;
                        $record->owner_id = null;
                        $record->customer_user_login = null;

                        if ($state) {
                            $lookupService = app(ZnunyCachedLookupService::class);
                            $candidate = $lookupService->resolveTemplateCandidate($state);
                            if ($candidate) {
                                $record->customer_user_login = $candidate;
                            }

                            $ownerOptions = $lookupService->getAssignableOwnerOptionsForQueue($state);
                            if (count($ownerOptions) === 1) {
                                $onlyOwnerKey = array_key_first($ownerOptions);
                                $onlyOwnerLabel = $ownerOptions[$onlyOwnerKey];
                                if (is_numeric($onlyOwnerKey) && $onlyOwnerKey > 0) {
                                    $record->owner_id = (int) $onlyOwnerKey;
                                    $record->owner_login = (string) $onlyOwnerLabel;
                                }
                            }
                        }
                        $record->save();
                    }),
                SelectColumn::make('customer_user_login')
                    ->label(__('scheduled_znuny_tasks.table.customer_user'))
                    ->placeholder(__('scheduled_znuny_tasks.placeholders.not_resolved'))
                    ->extraAttributes(['class' => 'scheduled-znuny-select'])
                    ->extraHeaderAttributes(['class' => 'scheduled-znuny-header'])
                    ->native(false)
                    ->optionsLimit(10000)
                    ->getOptionLabelUsing(function ($value) {
                        if (empty($value)) {
                            return null;
                        }
                        try {
                            $label = app(ZnunyCachedLookupService::class)->getCustomerUserLabel((string) $value);

                            return $label ?: (string) $value;
                        } catch (\Throwable $e) {
                            return (string) $value;
                        }
                    })
                    ->options(function (ScheduledZnunyTask $record) {
                        $queue = $record->queue_name;
                        if (empty($queue)) {
                            return [];
                        }

                        if (! request()->hasHeader('X-Livewire')) {
                            return [];
                        }

                        $options = app(ScheduledZnunyTasksRequestProfiler::class)->measure('customer_lookup', function () use ($queue) {
                            try {
                                return app(ZnunyCachedLookupService::class)->getCustomerUserPrimaryOptionsForQueue($queue);
                            } catch (\Throwable $e) {
                                return [];
                            }
                        });

                        try {
                            $current = $record->customer_user_login;
                            if ($current && ! isset($options[$current])) {
                                $lookupService = app(ZnunyCachedLookupService::class);
                                $label = $lookupService->getCustomerUserLabel($current);
                                if ($label) {
                                    $options[$current] = $label;
                                } else {
                                    $options[$current] = $current;
                                }
                            }

                            return $options;
                        } catch (\Throwable $e) {
                            return $options ?? [];
                        }
                    })
                    ->updateStateUsing(function (ScheduledZnunyTask $record, $state) {
                        if ($record->enabled && empty($state)) {
                            Notification::make()->title(__('scheduled_znuny_tasks.notifications.cannot_clear_customer_user.title'))->body(__('scheduled_znuny_tasks.notifications.cannot_clear_customer_user.body'))->danger()->send();

                            return ['error' => 'Customer User is required for active tasks.'];
                        }

                        $record->customer_user_login = empty($state) ? null : $state;
                        $record->save();
                    }),
                SelectColumn::make('owner_id')
                    ->label(__('scheduled_znuny_tasks.table.owner'))
                    ->placeholder(__('scheduled_znuny_tasks.placeholders.not_selected'))
                    ->extraAttributes(['class' => 'scheduled-znuny-select'])
                    ->extraHeaderAttributes(['class' => 'scheduled-znuny-header'])
                    ->native(false)
                    ->optionsLimit(10000)
                    ->getOptionLabelUsing(function ($value, ScheduledZnunyTask $record) {
                        if (empty($value)) {
                            return null;
                        }
                        if ($value == $record->owner_id && ! empty($record->owner_login)) {
                            return $record->owner_login;
                        }

                        return (string) $value;
                    })
                    ->options(function (ScheduledZnunyTask $record) {
                        $queue = $record->queue_name ?? '';
                        if (empty($queue)) {
                            return [];
                        }

                        if (! request()->hasHeader('X-Livewire')) {
                            return [];
                        }

                        $options = app(ScheduledZnunyTasksRequestProfiler::class)->measure('owner_lookup', function () use ($queue) {
                            try {
                                return app(ZnunyCachedLookupService::class)->getAssignableOwnerOptionsForQueue($queue);
                            } catch (\Throwable $e) {
                                return [];
                            }
                        });

                        try {
                            $current = $record->owner_id;
                            if ($current && ! isset($options[$current])) {
                                $options[$current] = $record->owner_login ?: $current;
                            }

                            return $options;
                        } catch (\Throwable $e) {
                            return $options ?? [];
                        }
                    })
                    ->updateStateUsing(function (ScheduledZnunyTask $record, $state) {
                        if ($record->enabled && empty($state)) {
                            Notification::make()->title(__('scheduled_znuny_tasks.notifications.cannot_clear_owner.title'))->body(__('scheduled_znuny_tasks.notifications.cannot_clear_owner.body'))->danger()->send();

                            return ['error' => 'Owner is required for active tasks.'];
                        }

                        if (empty($state)) {
                            $record->owner_id = null;
                            $record->owner_login = null;
                        } else {
                            $record->owner_id = (int) $state;
                            try {
                                $options = app(ZnunyCachedLookupService::class)->getAssignableOwnerOptionsForQueue($record->queue_name ?? '');
                                $label = $options[$state] ?? null;
                                $record->owner_login = $label ? (string) $label : null;
                            } catch (\Throwable $e) {
                                $record->owner_login = null;
                            }
                        }
                        $record->save();
                    }),
                TextColumn::make('last_status')
                    ->label(__('scheduled_znuny_tasks.table.last_result'))
                    ->extraHeaderAttributes(['class' => 'scheduled-znuny-header'])
                    ->formatStateUsing(function (?string $state) {
                        return ScheduledZnunyTaskResource::getStatusLabel($state);
                    })
                    ->badge()
                    ->extraCellAttributes(fn (ScheduledZnunyTask $record) => ['data-scheduled-sort-value' => $record->last_status ?? '']),
            ])
            ->paginated(false)
            ->recordUrl(fn (ScheduledZnunyTask $record) => ScheduledZnunyTaskResource::getUrl('edit', ['record' => $record]))
            ->recordActions([])
            ->toolbarActions([]);
    }
}
