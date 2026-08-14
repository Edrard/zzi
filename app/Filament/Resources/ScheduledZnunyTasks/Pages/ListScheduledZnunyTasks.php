<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Pages;

use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use App\Filament\Resources\ScheduledZnunyTasks\Widgets\SchedulerStatusConsole;
use App\Models\ScheduledZnunyTask;
use App\Services\Znuny\ZnunyCachedLookupService;
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
            if (method_exists($this, 'resetTablePage')) {
                $this->resetTablePage();
            }
        }
    }

    public function getQueueOptions(): array
    {
        return ScheduledZnunyTask::whereNotNull('queue_name')->distinct()->pluck('queue_name')->toArray();
    }

    public function getOwnerOptions(): array
    {
        $tasks = ScheduledZnunyTask::whereNotNull('owner_id')
            ->select('owner_id', 'owner_login', 'queue_name')
            ->get();

        $lookupService = app(ZnunyCachedLookupService::class);
        $options = [];

        foreach ($tasks as $task) {
            $ownerId = (int) $task->owner_id;

            if (! isset($options[$ownerId])) {
                $fallback = ! empty($task->owner_login) ? $task->owner_login : null;
                $options[$ownerId] = $lookupService->getCanonicalOwnerLabel($ownerId, $fallback);
            }
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
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
