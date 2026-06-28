<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use App\Services\Support\DateTimeDisplayService;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('created_at')
                    ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatDateTime($state)),
                TextEntry::make('user.name'),
                TextEntry::make('user.email'),
                TextEntry::make('action'),
                TextEntry::make('entity_type'),
                TextEntry::make('entity_id'),
                TextEntry::make('ip_address'),
                TextEntry::make('user_agent'),
                ViewEntry::make('context')
                    ->view('filament.infolists.audit-log-context'),
            ]);
    }
}
