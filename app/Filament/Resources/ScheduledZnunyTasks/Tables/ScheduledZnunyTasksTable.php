<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Tables;

use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use App\Models\ScheduledZnunyTask;
use App\Services\Cron\CronService;
use App\Services\Znuny\ZnunyCachedLookupService;
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
    public static function configure(Table $table): Table
    {
        return $table
            ->recordClasses(fn (ScheduledZnunyTask $record) => match ($record->enabled) {
                false => 'scheduled-task-disabled-row',
                default => null,
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
                    $query->where('owner_id', $livewire->getOwnerFilter());
                }

                if (method_exists($livewire, 'getActiveFilter') && $livewire->getActiveFilter() !== 'all') {
                    $query->where('enabled', $livewire->getActiveFilter() === '1');
                }
            })
            ->columns([
                ToggleColumn::make('enabled')
                    ->label('Active')
                    ->extraCellAttributes(fn (ScheduledZnunyTask $record) => ['data-scheduled-sort-value' => $record->enabled ? '1' : '0'])
                    ->rules(function (ScheduledZnunyTask $record) {
                        return [
                            function (string $attribute, $value, \Closure $fail) use ($record) {
                                if ($value) {
                                    if (! $record->isCompleteForScheduling()) {
                                        $missing = $record->missingSchedulingRequirements();
                                        Notification::make()
                                            ->title('Cannot enable task')
                                            ->body("Task is incomplete:\n- ".implode("\n- ", $missing))
                                            ->danger()
                                            ->send();
                                        $fail('Task is incomplete.');

                                        return;
                                    }

                                    $cronService = app(CronService::class);
                                    $nextRunAt = $cronService->calculateNextRun($record->cron_expression, $record->timezone);
                                    if ($nextRunAt === null) {
                                        Notification::make()
                                            ->title('Cannot enable task')
                                            ->body('Could not calculate next run time. Check timezone and cron expression.')
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
                        if ($state && $record->isCompleteForScheduling()) {
                            $next = $cronService->calculateNextRun($record->cron_expression, $record->timezone);
                            $record->next_run_at = $next ? $next->utc()->toDateTimeString() : null;
                        } else {
                            $record->next_run_at = null;
                        }

                        $record->enabled = $state;
                        $record->save();
                    }),
                TextColumn::make('name')
                    ->description(fn (ScheduledZnunyTask $record) => $record->subject)
                    ->extraCellAttributes(fn (ScheduledZnunyTask $record) => ['data-scheduled-sort-value' => $record->name ?? '']),
                TextInputColumn::make('cron_expression')
                    ->label('Cron')
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
                                ->title('Validation Error')
                                ->body('Invalid 5-field cron expression.')
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
                    ->label('Next run at')
                    ->extraCellAttributes(function (ScheduledZnunyTask $record) {
                        $cron = $record->cron_expression;
                        $tz = $record->timezone;
                        if (empty($cron) || empty($tz) || ! app(CronService::class)->isValid($cron)) {
                            return ['data-scheduled-sort-value' => ''];
                        }
                        $next = $record->next_run_at;

                        return ['data-scheduled-sort-value' => $next ? Carbon::parse($next)->timestamp : ''];
                    })
                    ->getStateUsing(function (ScheduledZnunyTask $record) {
                        $cron = $record->cron_expression;
                        $tz = $record->timezone;

                        if (empty($cron) || empty($tz) || ! app(CronService::class)->isValid($cron)) {
                            return null;
                        }

                        $next = $record->next_run_at;

                        if (! $next) {
                            return null;
                        }

                        return Carbon::parse($next)->timezone($tz)->format('Y-m-d H:i:s e');
                    })
                    ->placeholder('Not calculated'),
                SelectColumn::make('queue_name')
                    ->label('Queue')
                    ->placeholder('Not selected')
                    ->options(function () {
                        static $queueOptionsCache = null;
                        if ($queueOptionsCache !== null) {
                            return $queueOptionsCache;
                        }

                        try {
                            $options = app(ZnunyCachedLookupService::class)->getFilteredQueueOptions();
                            $queueOptionsCache = $options;

                            return $options;
                        } catch (\Throwable $e) {
                            return [];
                        }
                    })
                    ->updateStateUsing(function (ScheduledZnunyTask $record, $state) {
                        if ($record->enabled && empty($state)) {
                            Notification::make()->title('Cannot clear Queue')->body('Active tasks require a Queue.')->danger()->send();

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
                    ->label('Customer User')
                    ->placeholder('Not resolved')
                    ->options(function (ScheduledZnunyTask $record) {
                        static $customerOptionsCache = [];
                        $queue = $record->queue_name;
                        if (empty($queue)) {
                            return [];
                        }

                        if (isset($customerOptionsCache[$queue])) {
                            $options = $customerOptionsCache[$queue];
                        } else {
                            try {
                                $lookupService = app(ZnunyCachedLookupService::class);
                                $options = $lookupService->getCustomerUserPrimaryOptionsForQueue($queue);
                                $customerOptionsCache[$queue] = $options;
                            } catch (\Throwable $e) {
                                return [];
                            }
                        }

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
                            Notification::make()->title('Cannot clear Customer User')->body('Active tasks require a Customer User.')->danger()->send();

                            return ['error' => 'Customer User is required for active tasks.'];
                        }

                        $record->customer_user_login = empty($state) ? null : $state;
                        $record->save();
                    }),
                SelectColumn::make('owner_id')
                    ->label('Owner')
                    ->placeholder('Not selected')
                    ->options(function (ScheduledZnunyTask $record) {
                        static $ownerOptionsCache = [];
                        $queue = $record->queue_name ?? '';

                        if (isset($ownerOptionsCache[$queue])) {
                            $options = $ownerOptionsCache[$queue];
                        } else {
                            try {
                                $options = app(ZnunyCachedLookupService::class)->getAssignableOwnerOptionsForQueue($queue);
                                $ownerOptionsCache[$queue] = $options;
                            } catch (\Throwable $e) {
                                return [];
                            }
                        }

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
                            Notification::make()->title('Cannot clear Owner')->body('Active tasks require an Owner.')->danger()->send();

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
                    ->label('Last result')
                    ->badge()
                    ->extraCellAttributes(fn (ScheduledZnunyTask $record) => ['data-scheduled-sort-value' => $record->last_status ?? '']),
            ])
            ->defaultSort('id', 'desc')
            ->paginated(false)
            ->recordUrl(fn (ScheduledZnunyTask $record) => ScheduledZnunyTaskResource::getUrl('edit', ['record' => $record]))
            ->recordActions([])
            ->toolbarActions([]);
    }
}
