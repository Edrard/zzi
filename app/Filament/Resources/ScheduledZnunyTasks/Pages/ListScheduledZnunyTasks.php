<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Pages;

use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use App\Filament\Resources\ScheduledZnunyTasks\Widgets\SchedulerStatusConsole;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListScheduledZnunyTasks extends ListRecords
{
    protected static string $resource = ScheduledZnunyTaskResource::class;

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
        return [
            SchedulerStatusConsole::class,
        ];
    }
}
