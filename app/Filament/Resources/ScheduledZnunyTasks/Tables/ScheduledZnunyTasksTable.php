<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Tables;

use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use App\Models\ScheduledZnunyTask;
use App\Services\Cron\CronService;
use App\Services\Support\DateTimeDisplayService;
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
                    ->extraCellAttributes(fn (ScheduledZnunyTask $record) => [
                        'data-scheduled-sort-value' => $record->enabled ? '1' : '0',
                        'data-scheduled-record-id' => $record->id,
                    ])
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
                    ->extraCellAttributes(fn (ScheduledZnunyTask $record) => [
                        'data-scheduled-sort-value' => $record->cron_expression ?? '',
                        'data-scheduled-record-id' => $record->id,
                    ])
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
                        $cron = $record->cron_expression;
                        $tz = $record->timezone;

                        $cronService = app(CronService::class);
                        if (empty($cron) || empty($tz) || ! $cronService->isValid($cron)) {
                            return null;
                        }

                        $next = $cronService->calculateNextRun($cron, $tz);

                        return $next ? $next->utc()->toDateTimeString() : null;
                    })
                    ->formatStateUsing(function (?string $state) {
                        if (empty($state)) {
                            return null;
                        }

                        return app(DateTimeDisplayService::class)->formatDateTime($state);
                    })
                    ->placeholder(__('scheduled_znuny_tasks.placeholders.not_calculated')),
                TextColumn::make('queue_name')
                    ->label(__('scheduled_znuny_tasks.table.queue'))
                    ->placeholder(__('scheduled_znuny_tasks.placeholders.not_selected'))
                    ->extraHeaderAttributes(['class' => 'scheduled-znuny-header']),
                TextColumn::make('customer_user_login')
                    ->label(__('scheduled_znuny_tasks.table.customer_user'))
                    ->placeholder(__('scheduled_znuny_tasks.placeholders.not_resolved'))
                    ->extraHeaderAttributes(['class' => 'scheduled-znuny-header'])
                    ->formatStateUsing(function ($state) {
                        if (empty($state)) {
                            return null;
                        }
                        try {
                            $label = app(ZnunyCachedLookupService::class)->getCustomerUserLabel((string) $state);

                            return $label ?: (string) $state;
                        } catch (\Throwable $e) {
                            return (string) $state;
                        }
                    }),
                SelectColumn::make('owner_id')
                    ->label(__('scheduled_znuny_tasks.table.owner'))
                    ->placeholder(__('scheduled_znuny_tasks.placeholders.not_selected'))
                    ->extraAttributes(['class' => 'scheduled-znuny-select'])
                    ->extraHeaderAttributes(['class' => 'scheduled-znuny-header'])
                    ->extraCellAttributes(fn (ScheduledZnunyTask $record) => ['data-scheduled-record-id' => $record->id])
                    ->native(false)
                    ->optionsLimit(10000)
                    ->getOptionLabelUsing(function ($value, ScheduledZnunyTask $record) {
                        if (empty($value)) {
                            return null;
                        }

                        $fallback = ! empty($record->owner_login) ? $record->owner_login : null;

                        return app(ZnunyCachedLookupService::class)->getCanonicalOwnerLabel((int) $value, $fallback);
                    })
                    ->options(function (ScheduledZnunyTask $record) {
                        $queue = $record->queue_name ?? '';
                        if (empty($queue)) {
                            return [];
                        }

                        if (! request()->hasHeader('X-Livewire')) {
                            return [];
                        }

                        try {
                            $options = app(ZnunyCachedLookupService::class)->getAssignableHumanOwnerOptionsForQueue($queue);
                        } catch (\Throwable $e) {
                            $options = [];
                        }

                        try {
                            $current = $record->owner_id;
                            if ($current && ! isset($options[$current])) {
                                $fallback = ! empty($record->owner_login) ? $record->owner_login : null;
                                $options[$current] = app(ZnunyCachedLookupService::class)->getCanonicalOwnerLabel((int) $current, $fallback);
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
                                $options = app(ZnunyCachedLookupService::class)->getAssignableHumanOwnerOptionsForQueue($record->queue_name ?? '');
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
                    ->color(fn (?string $state): string => match ($state) {
                        'success' => 'success',
                        'failed', 'error' => 'danger',
                        default => 'primary',
                    })
                    ->extraCellAttributes(fn (ScheduledZnunyTask $record) => ['data-scheduled-sort-value' => $record->last_status ?? '']),
            ])
            ->paginated(false)
            ->recordUrl(fn (ScheduledZnunyTask $record) => ScheduledZnunyTaskResource::getUrl('edit', ['record' => $record]))
            ->recordActions([])
            ->toolbarActions([]);
    }
}
