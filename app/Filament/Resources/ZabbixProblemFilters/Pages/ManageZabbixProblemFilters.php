<?php

namespace App\Filament\Resources\ZabbixProblemFilters\Pages;

use App\Filament\Resources\ZabbixProblemFilters\ZabbixProblemFilterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageZabbixProblemFilters extends ManageRecords
{
    protected static string $resource = ZabbixProblemFilterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => in_array(auth()->user()->role, ['admin', 'operator'], true)),
        ];
    }
}
