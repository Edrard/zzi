<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Pages;

use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use Filament\Actions\DeleteAction;
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
            DeleteAction::make(),
        ];
    }
}
