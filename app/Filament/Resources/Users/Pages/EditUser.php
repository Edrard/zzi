<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::SixExtraLarge;
    }

    protected array $originalAuditData = [];

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->originalAuditData = [
            'name' => $this->record->name,
            'email' => $this->record->email,
            'role' => $this->record->role,
            'password' => $this->record->password,
            'is_active' => $this->record->is_active,
        ];

        if ($this->record->id === auth()->id()) {
            $data['role'] = 'admin';
            $data['is_active'] = true;
        }

        if (
            $this->record->role === 'admin' &&
            $this->record->is_active &&
            isset($data['is_active']) &&
            $data['is_active'] === false &&
            User::where('role', 'admin')->where('is_active', true)->where('id', '!=', $this->record->id)->count() === 0
        ) {
            $data['is_active'] = true;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $changes = [];

        foreach (['name', 'email', 'role'] as $field) {
            if ($this->originalAuditData[$field] !== $this->record->$field) {
                $changes[] = [
                    'key' => $field,
                    'old_value' => $this->originalAuditData[$field],
                    'new_value' => $this->record->$field,
                ];
            }
        }

        if ($this->originalAuditData['password'] !== $this->record->password) {
            $changes[] = [
                'key' => 'password_changed',
                'old_value' => false,
                'new_value' => true,
            ];
        }

        if (! empty($changes)) {
            AuditLogger::log(
                action: 'user.updated',
                entityType: 'user',
                entityId: $this->record->id,
                context: ['changes' => $changes]
            );
        }

        if (isset($this->originalAuditData['is_active']) && $this->originalAuditData['is_active'] !== $this->record->is_active) {
            if ($this->record->is_active) {
                AuditLogger::log(
                    action: 'user.unlocked',
                    entityType: 'user',
                    entityId: $this->record->id,
                );
            } else {
                AuditLogger::log(
                    action: 'user.locked',
                    entityType: 'user',
                    entityId: $this->record->id,
                );
            }
        }
    }
}
