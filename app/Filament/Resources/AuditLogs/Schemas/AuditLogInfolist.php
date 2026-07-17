<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Services\Support\DateTimeDisplayService;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('audit_logs.infolist.sections.details.heading'))
                    ->schema([
                        TextEntry::make('created_at')
                            ->label(__('audit_logs.infolist.entries.created_at.label'))
                            ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatLocalizedDateTime($state)),
                        TextEntry::make('user.name')
                            ->label(__('audit_logs.infolist.entries.user.label'))
                            ->formatStateUsing(fn ($state) => AuditLogResource::actorLabel($state)),
                        TextEntry::make('user.email'),
                        TextEntry::make('action')
                            ->label(__('audit_logs.infolist.entries.action.label'))
                            ->formatStateUsing(fn ($state) => AuditLogResource::actionLabel($state)),
                        TextEntry::make('entity_type')
                            ->label(__('audit_logs.infolist.entries.entity_type.label'))
                            ->formatStateUsing(fn ($state) => AuditLogResource::entityTypeLabel($state)),
                        TextEntry::make('entity_id')
                            ->label(__('audit_logs.infolist.entries.entity_id.label')),
                        TextEntry::make('ip_address')
                            ->label(__('audit_logs.infolist.entries.ip_address.label')),
                        TextEntry::make('user_agent')
                            ->label(__('audit_logs.infolist.entries.user_agent.label')),
                    ])->columns(2),
                Section::make(__('audit_logs.infolist.sections.context.heading'))
                    ->schema([
                        ViewEntry::make('context')
                            ->hiddenLabel()
                            ->view('filament.infolists.audit-log-context'),
                    ]),
            ]);
    }
}
