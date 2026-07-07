<?php

namespace App\Filament\Resources\ScheduledZnunyTaskRuns\Pages;

use App\Filament\Resources\ScheduledZnunyTaskRuns\ScheduledZnunyTaskRunResource;
use Filament\Resources\Pages\ManageRecords;

class ManageScheduledZnunyTaskRuns extends ManageRecords
{
    protected static string $resource = ScheduledZnunyTaskRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No CreateAction for read-only log
        ];
    }
}
