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

        $options = [];
        $unresolved = [];

        // First pass: try to resolve from owner_login
        foreach ($tasks as $task) {
            $isRawId = (string) $task->owner_login === (string) $task->owner_id;
            if (! empty($task->owner_login) && ! $isRawId) {
                $options[$task->owner_id] = $task->owner_login;
            } elseif (! isset($options[$task->owner_id])) {
                $unresolved[$task->owner_id] = $task->queue_name;
            }
        }

        // Second pass: try to resolve missing from lookup service
        $lookupService = app(ZnunyCachedLookupService::class);
        foreach ($unresolved as $ownerId => $queueName) {
            if (isset($options[$ownerId])) {
                continue; // was resolved by another task
            }

            $resolved = false;
            if ($queueName) {
                try {
                    $queueOptions = $lookupService->getAssignableOwnerOptionsForQueue($queueName);
                    if (isset($queueOptions[$ownerId])) {
                        $options[$ownerId] = (string) $queueOptions[$ownerId];
                        $resolved = true;
                    }
                } catch (\Throwable $e) {
                    // Ignore API/cache errors and fallback
                }
            }

            if (! $resolved) {
                $options[$ownerId] = "Owner ID: {$ownerId}";
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
