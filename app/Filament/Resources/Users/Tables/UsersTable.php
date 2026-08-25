<?php

namespace App\Filament\Resources\Users\Tables;

use App\Services\Support\DateTimeDisplayService;
use App\Support\Pagination\PaginationSettings;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(app(PaginationSettings::class)->defaultPerPage())
            ->paginationPageOptions(app(PaginationSettings::class)->perPageOptions())
            ->columns([
                TextColumn::make('name')
                    ->label(__('users.table.columns.name.label'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('users.table.columns.email.label'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->label(__('users.table.columns.role.label'))
                    ->formatStateUsing(fn ($state) => __('users.roles.'.$state))
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('users.table.columns.is_active.label'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('users.table.columns.created_at.label'))
                    ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatDateTime($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('users.table.columns.updated_at.label'))
                    ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatDateTime($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                // No bulk actions allowed
            ]);
    }
}
