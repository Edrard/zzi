<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Pages;

use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use App\Filament\Resources\ScheduledZnunyTasks\Widgets\SchedulerStatusConsole;
use App\Models\ScheduledZnunyTask;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListScheduledZnunyTasks extends ListRecords
{
    protected static string $resource = ScheduledZnunyTaskResource::class;

    protected string $view = 'filament.resources.scheduled-znuny-tasks.pages.list-scheduled-znuny-tasks';

    public $taskSearch = '';

    public $queueFilter = '';

    public $ownerFilter = '';

    public $activeFilter = 'all';

    public function getTaskSearch()
    {
        return $this->taskSearch;
    }

    public function getQueueFilter()
    {
        return $this->queueFilter;
    }

    public function getOwnerFilter()
    {
        return $this->ownerFilter;
    }

    public function getActiveFilter()
    {
        return $this->activeFilter;
    }

    public function updated($property)
    {
        if (in_array($property, ['taskSearch', 'queueFilter', 'ownerFilter', 'activeFilter'])) {
            $this->resetTablePage();
        }
    }

    public function getQueueOptions(): array
    {
        return ScheduledZnunyTask::whereNotNull('queue_name')->distinct()->pluck('queue_name')->toArray();
    }

    public function getOwnerOptions(): array
    {
        return ScheduledZnunyTask::whereNotNull('owner_login')->distinct()->pluck('owner_login')->toArray();
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        $widgets = [];

        $user = auth()->user();
        if ($user && method_exists($user, 'canViewScheduledTasksStatusPanel') && $user->canViewScheduledTasksStatusPanel()) {
            $widgets[] = SchedulerStatusConsole::class;
        }

        return $widgets;
    }
}
