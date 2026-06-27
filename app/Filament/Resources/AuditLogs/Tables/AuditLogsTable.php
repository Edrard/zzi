<?php

namespace App\Filament\Resources\AuditLogs\Tables;

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
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('user.name')->searchable(),
                TextColumn::make('action')->searchable(),
                TextColumn::make('entity_type')->searchable(),
                TextColumn::make('entity_id')->searchable(),
                TextColumn::make('ip_address')->searchable(),
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
