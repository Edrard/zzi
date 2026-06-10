<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::SixExtraLarge;
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
