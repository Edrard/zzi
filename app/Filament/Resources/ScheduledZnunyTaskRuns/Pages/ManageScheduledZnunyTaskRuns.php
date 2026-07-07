<?php

namespace App\Filament\Resources\ScheduledZnunyTaskRuns\Pages;

use App\Filament\Resources\ScheduledZnunyTaskRuns\ScheduledZnunyTaskRunResource;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageScheduledZnunyTaskRuns extends ManageRecords
{
    protected static string $resource = ScheduledZnunyTaskRunResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            // No CreateAction for read-only log
        ];
    }
}
