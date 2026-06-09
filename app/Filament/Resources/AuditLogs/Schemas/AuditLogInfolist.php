<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('created_at')->disabled(),
                TextInput::make('user.email')->disabled(),
                TextInput::make('action')->disabled(),
                TextInput::make('entity_type')->disabled(),
                TextInput::make('entity_id')->disabled(),
                TextInput::make('ip_address')->disabled(),
                Textarea::make('user_agent')->disabled(),
                KeyValue::make('context')->disabled(),
            ]);
    }
}
