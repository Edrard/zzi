<?php

namespace App\Filament\Resources\AttentionFilters\Pages;

use App\Filament\Resources\AttentionFilters\AttentionFilterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAttentionFilters extends ManageRecords
{
    protected static string $resource = AttentionFilterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => in_array(auth()->user()->role, ['admin', 'operator'], true)),
        ];
    }
}
