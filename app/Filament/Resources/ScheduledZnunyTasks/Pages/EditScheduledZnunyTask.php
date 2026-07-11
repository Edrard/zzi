<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Pages;

use App\Filament\Resources\ScheduledZnunyTaskRuns\ScheduledZnunyTaskRunResource;
use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use App\Models\ScheduledZnunyTaskRun;
use App\Services\SchedulerSafetyService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditScheduledZnunyTask extends EditRecord
{
    protected static string $resource = ScheduledZnunyTaskResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('enqueue_run')
                ->label('Queue run')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->requiresConfirmation()
                ->visible(fn () => $this->record->enabled && $this->record->isCompleteForScheduling())
                ->action(function () {
                    ScheduledZnunyTaskRun::create([
                        'scheduled_znuny_task_id' => $this->record->id,
                        'task_name_snapshot' => $this->record->name,
                        'run_type' => 'manual',
                        'status' => 'pending',
                        'scheduled_for' => now('UTC')->toDateTimeString(),
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
                ->url(fn (): string => ScheduledZnunyTaskRunResource::getUrl('index', [
                    'tableFilters' => [
                        'scheduled_znuny_task_id' => ['value' => $this->record->id],
                    ],
                ])),
            DeleteAction::make(),
        ];
    }
}
