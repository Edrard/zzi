<?php

namespace App\Filament\Resources\DailyStatistics\Pages;

use App\Filament\Resources\DailyStatistics\DailyStatisticResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDailyStatistic extends ViewRecord
{
    protected static string $resource = DailyStatisticResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
