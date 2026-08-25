<?php

namespace App\Filament\Resources\AttentionFilters\Pages;

use App\Filament\Resources\AttentionFilters\AttentionFilterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageAttentionFilters extends ManageRecords
{
    protected static string $resource = AttentionFilterResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('attention_filters.resource.plural');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->modalHeading(__('attention_filters.actions.create.heading'))
                ->successNotificationTitle(__('attention_filters.notifications.created'))
                ->failureNotificationTitle(__('attention_filters.notifications.save_failed'))
                ->visible(fn () => in_array(auth()->user()->role, ['admin', 'operator'], true)),
        ];
    }
}
