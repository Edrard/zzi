<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Tables;

use App\Filament\Resources\ScheduledZnunyTaskRuns\ScheduledZnunyTaskRunResource;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Services\Cron\CronService;
use App\Services\SchedulerSafetyService;
use App\Services\Znuny\ZnunyCachedLookupService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class ScheduledZnunyTasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordClasses(fn (ScheduledZnunyTask $record) => match ($record->enabled) {
                false => 'bg-gray-50 dark:bg-gray-800/50',
                default => null,
            })
            ->columns([
                ToggleColumn::make('enabled')
                    ->label('Active')
                    ->updateStateUsing(function (ScheduledZnunyTask $record, $state) {
                        if ($state) { // if enabling
                            $cronService = app(CronService::class);
                            if (empty($record->cron_expression) || ! $cronService->isValid($record->cron_expression)) {
                                Notification::make()
                                    ->title('Cannot enable task')
                                    ->body('A valid cron expression is required.')
                                    ->danger()
                                    ->send();

                                return;
                            }
                            if (empty($record->queue_name)) {
                                Notification::make()
                                    ->title('Cannot enable task')
                                    ->body('Queue override is required.')
                                    ->danger()
                                    ->send();

                                return;
                            }
                            if (empty($record->owner_login)) {
                                Notification::make()
                                    ->title('Cannot enable task')
                                    ->body('Owner override is required.')
                                    ->danger()
                                    ->send();

                                return;
                            }
                            if (empty($record->subject)) {
                                Notification::make()
                                    ->title('Cannot enable task')
                                    ->body('Subject is required.')
                                    ->danger()
                                    ->send();

                                return;
                            }
                            if (empty($record->body)) {
                                Notification::make()
                                    ->title('Cannot enable task')
                                    ->body('Body is required.')
                                    ->danger()
                                    ->send();

                                return;
                            }
                            if (empty($record->customer_user_login)) {
                                Notification::make()
                                    ->title('Cannot enable task')
                                    ->body('Customer User is required.')
                                    ->danger()
                                    ->send();

                                return;
                            }
                            // Calculate next_run_at when enabling
                            $nextRunAt = $cronService->calculateNextRun($record->cron_expression, $record->timezone);
                            if ($nextRunAt === null) {
                                Notification::make()
                                    ->title('Cannot enable task')
                                    ->body('Next run time could not be calculated.')
                                    ->danger()
                                    ->send();

                                return;
                            }
                            $record->next_run_at = $nextRunAt;
                        }

                        $record->enabled = $state;
                        $record->save();
                    }),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ScheduledZnunyTask $record) => $record->subject),
                TextInputColumn::make('cron_expression')
                    ->label('Cron')
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

                            return;
                        }

                        $record->cron_expression = $state;
                        $record->next_run_at = $cronService->calculateNextRun($state, $record->timezone);
                        $record->save();
                    }),
                TextColumn::make('next_run_at')
                    ->dateTime()
                    ->sortable(),
                SelectColumn::make('queue_name')
                    ->label('Queue')
                    ->options(function () {
                        try {
                            return app(ZnunyCachedLookupService::class)->getFilteredQueueOptions();
                        } catch (\Throwable $e) {
                            return [];
                        }
                    })
                    ->searchable()
                    ->updateStateUsing(function (ScheduledZnunyTask $record, $state) {
                        $record->queue_name = $state;
                        // Reset owner and customer user if queue changes
                        $record->owner_login = null;
                        $record->owner_id = null;
                        if ($state) {
                            $candidate = app(ZnunyCachedLookupService::class)->resolveTemplateCandidate($state);
                            if ($candidate) {
                                $record->customer_user_login = $candidate;
                            }
                        } else {
                            $record->customer_user_login = null;
                        }
                        $record->save();
                    }),
                SelectColumn::make('owner_login')
                    ->label('Owner')
                    ->options(function (ScheduledZnunyTask $record) {
                        try {
                            return app(ZnunyCachedLookupService::class)->getAssignableOwnerOptionsForQueue($record->queue_name ?? '');
                        } catch (\Throwable $e) {
                            return [];
                        }
                    })
                    ->searchable(),
                TextColumn::make('last_status')
                    ->label('Status')
                    ->searchable()
                    ->badge(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('enqueue_run')
                    ->label('Queue run')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function (ScheduledZnunyTask $record) {
                        ScheduledZnunyTaskRun::create([
                            'scheduled_znuny_task_id' => $record->id,
                            'task_name_snapshot' => $record->name,
                            'run_type' => 'manual',
                            'status' => 'pending',
                            'scheduled_for' => now(),
                            'created_by' => auth()->id(),
                        ]);

                        $safetyService = app(SchedulerSafetyService::class);
                        if (! $safetyService->isSchedulerEnabled() || $safetyService->isSchedulerPaused()) {
                            Notification::make()->title('Run Queued')->body('The run has been queued, but the scheduler is currently disabled or paused. It will remain pending.')->warning()->send();
                        } else {
                            Notification::make()->title('Run Queued')->body('The run has been queued and will be processed by the scheduler shortly.')->success()->send();
                        }
                    }),
                Action::make('runs')
                    ->label('Runs')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->url(fn (ScheduledZnunyTask $record): string => ScheduledZnunyTaskRunResource::getUrl('index', [
                        'tableFilters' => [
                            'scheduled_znuny_task_id' => ['value' => $record->id],
                        ],
                    ])),
            ])
            ->toolbarActions([]);
    }
}
