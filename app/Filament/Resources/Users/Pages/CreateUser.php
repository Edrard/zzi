<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::SixExtraLarge;
    }

    public function getTitle(): string|Htmlable
    {
        return __('users.pages.create.title');
    }

    protected function afterCreate(): void
    {
        AuditLogger::log(
            action: 'user.created',
            entityType: 'user',
            entityId: $this->record->id,
            context: [
                'name' => $this->record->name,
                'email' => $this->record->email,
                'role' => $this->record->role,
                'is_active' => $this->record->is_active,
            ]
        );
    }
}
