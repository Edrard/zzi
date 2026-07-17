<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Services\Support\DateTimeDisplayService;
use App\Support\Pagination\PaginationSettings;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('audit_logs.table.columns.created_at.label'))
                    ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatLocalizedDateTime($state))
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('audit_logs.table.columns.user.label'))
                    ->formatStateUsing(fn ($state) => AuditLogResource::actorLabel($state))
                    ->searchable(),
                TextColumn::make('action')
                    ->label(__('audit_logs.table.columns.action.label'))
                    ->formatStateUsing(fn ($state) => AuditLogResource::actionLabel($state))
                    ->searchable(),
                TextColumn::make('entity_type')
                    ->label(__('audit_logs.table.columns.entity_type.label'))
                    ->formatStateUsing(fn ($state) => AuditLogResource::entityTypeLabel($state))
                    ->searchable(),
                TextColumn::make('entity_id')
                    ->label(__('audit_logs.table.columns.entity_id.label'))
                    ->searchable(),
                TextColumn::make('ip_address')
                    ->label(__('audit_logs.table.columns.ip_address.label'))
                    ->searchable(),
            ])
            ->defaultPaginationPageOption(app(PaginationSettings::class)->defaultPerPage())
            ->paginationPageOptions(app(PaginationSettings::class)->perPageOptions())
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
