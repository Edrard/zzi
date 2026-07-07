<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Pages;

use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewScheduledZnunyTask extends ViewRecord
{
    protected static string $resource = ScheduledZnunyTaskResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
